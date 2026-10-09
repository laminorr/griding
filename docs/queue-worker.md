# Queue worker (`scripts/queue-worker.sh`)

## Why

The host crontab used to start the worker once a minute under `flock`:

```
queue:work database --sleep=1 --tries=3 --max-time=3540 --memory=256
```

When `--max-time` ran out, the worker exited. Nothing drained the `jobs` table
until the next cron minute, so each hour had a gap of up to ~60 s. The bot-48
audit showed one W4 event that waited 56.4 s (row 279) in that gap.

`scripts/queue-worker.sh` runs the same command in a loop and restarts it 1 s
after it exits, whatever the reason:

- `--max-time` or `--memory` reached;
- a crash;
- `php artisan queue:restart` on deploy. The next worker loads the new code.

Behaviour:

- **PHP binary**: chosen the same way as `scripts/ws-private-keepalive.sh`:
  `ea-php83`, then `ea-php84`, then `php` on the PATH.
- **Single instance**: `flock -n` on `storage/framework/queue-worker.lock`.
  Cron can start the script every minute; a second copy exits at once. The
  `queue:work` child does not inherit the lock fd. If the loop is killed, the
  next cron minute starts a new loop. That loop does not wait for the old
  worker to finish.
- **Log**: each start and exit goes to `storage/logs/queue-worker.log`, with
  the exit code.

## Crontab swap (host)

Do **not** pipe `crontab -l | … | crontab -`. That pattern once wiped this
host's crontab. Edit a copy, check the diff, then install the copy:

```bash
cd ~                                   # anywhere writable
crontab -l > crontab.bak               # 1. untouched backup
cp crontab.bak crontab.new
grep -n 'queue:work' crontab.new       # 2. must print exactly ONE line (the old worker line)

# 3. Replace that single line. PROJECT is the Laravel root: the same directory
#    the existing ws-keepalive.sh line uses.
PROJECT=/path/to/project               # <-- set from: grep ws-keepalive crontab.new
sed -i "\#queue:work database#c\\* * * * * /bin/bash $PROJECT/scripts/queue-worker.sh >/dev/null 2>\&1" crontab.new

diff crontab.bak crontab.new           # 4. exactly one line changed: old queue line -> new one
crontab crontab.new                    # 5. install
crontab -l | diff - crontab.new && echo INSTALLED_OK

# 6. End the old flock'd worker now instead of waiting up to 59 min for --max-time;
#    the new loop (started by cron within a minute) takes over.
ea-php83 artisan queue:restart
```

**New line:**

```
* * * * * /bin/bash /path/to/project/scripts/queue-worker.sh >/dev/null 2>&1
```

**Rollback:** `crontab crontab.bak`, then `pkill -f scripts/queue-worker.sh`
(this stops the loop). Then run `ea-php83 artisan queue:restart` (this stops
its current worker).

## Verify

- `pgrep -af scripts/queue-worker.sh` shows exactly one loop.
- `pgrep -af 'queue:work database'` shows one worker. Right after the swap,
  the old one may still show until `queue:restart` takes effect.
- `tail storage/logs/queue-worker.log` shows `starting queue:work (run N)`.
  About once an hour it shows `queue:work exited (code 0); restarting in 1s`,
  followed by the next start 1 s later.
- After a deploy's `queue:restart`, the log shows an exit and a new start
  within about 1 s.
- Within 24 h, the W4 latency in `bot:audit` should have no hourly ~60 s
  outliers.
