{{-- «نمودار قیمت» panel (Alpine priceChart()). Included twice by
     bot-monitoring.blade.php: inside an active bot's card, and standalone for a
     stopped bot / no bot. All data comes from $wire.getChartData(). --}}
<div class="panel-section at-pchart" x-data="priceChart()">
    <div class="panel-section__head">
        <div>
            <span class="panel-section__title">نمودار قیمت</span>
            <p class="panel-section__sub">
                <span class="at-mono" x-text="symbol || (typeof bot !== 'undefined' ? bot.symbol : '')"></span>
                <template x-if="unitLabel">
                    <span> · <span x-text="'واحد: ' + unitLabel"></span></span>
                </template>
                <template x-if="hasData && mode === 'bot'">
                    <span> · <span x-text="faDigits(levelCount) + ' سفارش باز روی نمودار'"></span></span>
                </template>
            </p>
        </div>
        <div class="at-pchart__tools">
            <span class="at-badge" :class="live ? 'pos' : 'muted'" x-show="hasData"
                  x-text="live ? 'زنده' : 'با تأخیر'"></span>
            <div class="at-pchart__tf" role="group" aria-label="بازه زمانی">
                <template x-for="tf in timeframes" :key="tf.res">
                    <button type="button" class="at-btn" :class="resolution === tf.res ? 'at-btn--accent' : ''"
                            :aria-pressed="resolution === tf.res" @click="setResolution(tf.res)" x-text="tf.label"></button>
                </template>
            </div>
            <button type="button" class="at-btn" x-show="mode !== 'market'"
                    :class="showFills ? 'at-btn--accent' : ''" :aria-pressed="showFills"
                    title="نمایش معاملات پُرشده روی نمودار" @click="toggleFills()">معاملات</button>
        </div>
    </div>
    <div class="panel-section__body at-pchart__body">
        <div class="at-pchart__canvas" x-ref="canvas" dir="ltr" x-show="hasData">
            <div class="at-pchart__legend" dir="rtl" x-show="legend" x-html="legend"></div>
        </div>
        <div class="at-empty at-pchart__empty" x-show="!hasData">
            <div class="at-empty__icon">📉</div>
            <span x-text="emptyMessage"></span>
        </div>
        <p class="at-pchart__note at-t-dim" x-show="hasData && note" x-text="note"></p>
    </div>
</div>
