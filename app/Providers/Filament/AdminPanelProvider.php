<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->profile()
            ->passwordReset()
            ->favicon(asset('favicon.ico'))
            ->brandName('Grid Trading Bot')
            ->brandLogo(asset('logo.svg'))
            ->brandLogoHeight('3rem')
            ->colors([
                'primary' => Color::Emerald,
                'gray' => Color::Slate,
                'info' => Color::Blue,
                'success' => Color::Green,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
            ])
            ->font('Vazirmatn')
            ->navigationGroups([
                'ربات‌ها' => 1,
                'معاملات' => 2,
                'گزارش‌ها' => 3,
                'تنظیمات' => 4,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->sidebarFullyCollapsibleOnDesktop()
            ->topNavigation(false)
            ->maxContentWidth(MaxWidth::Full)
            ->spa()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->unsavedChangesAlerts()
            // ->viteTheme('resources/css/filament/admin/theme.css')
            ->renderHook(
                'panels::styles.after',
                fn (): string => '
                <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" />
                <style>
                    :root {
                        --font-family: Vazirmatn, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
                        --sidebar-bg: linear-gradient(145deg, #0f172a 0%, #1e293b 50%, #334155 100%);
                        --sidebar-border: rgba(148, 163, 184, 0.1);
                        --sidebar-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
                        --sidebar-item-hover: rgba(16, 185, 129, 0.1);
                        --sidebar-item-active: rgba(16, 185, 129, 0.15);
                    }
                    
                    * {
                        font-family: var(--font-family) !important;
                    }
                    
                    body {
                        font-feature-settings: "ss02";
                    }
                    
                    /* ========== SIDEBAR STYLING ========== */
                    .fi-sidebar {
                        background: var(--sidebar-bg) !important;
                        border-right: 1px solid var(--sidebar-border) !important;
                        box-shadow: var(--sidebar-shadow) !important;
                        backdrop-filter: blur(20px) !important;
                        -webkit-backdrop-filter: blur(20px) !important;
                        position: relative !important;
                        z-index: 50 !important;
                    }
                    
                    /* Sidebar overlay effect */
                    .fi-sidebar::before {
                        content: "";
                        position: absolute;
                        top: 0;
                        left: 0;
                        right: 0;
                        bottom: 0;
                        background: linear-gradient(135deg, rgba(16, 185, 129, 0.03) 0%, rgba(59, 130, 246, 0.02) 100%);
                        pointer-events: none;
                        z-index: 1;
                    }
                    
                    .fi-sidebar > * {
                        position: relative;
                        z-index: 2;
                    }
                    
                    /* Brand/Logo area */
                    .fi-sidebar-header {
                        background: rgba(255, 255, 255, 0.05) !important;
                        backdrop-filter: blur(10px) !important;
                        border-bottom: 1px solid rgba(148, 163, 184, 0.1) !important;
                        padding: 1.5rem 1rem !important;
                        margin-bottom: 1rem !important;
                    }
                    
                    .fi-sidebar-header .fi-logo {
                        filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.3));
                        transition: transform 0.3s ease;
                    }
                    
                    .fi-sidebar-header:hover .fi-logo {
                        transform: scale(1.02);
                    }
                    
                    /* Navigation items */
                    .fi-sidebar-nav {
                        padding: 0 0.75rem !important;
                    }
                    
                    .fi-sidebar-nav-item {
                        margin-bottom: 0.25rem !important;
                        border-radius: 12px !important;
                        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
                        position: relative !important;
                        overflow: hidden !important;
                    }
                    
                    .fi-sidebar-nav-item::before {
                        content: "";
                        position: absolute;
                        top: 0;
                        left: 0;
                        right: 0;
                        bottom: 0;
                        background: linear-gradient(90deg, transparent 0%, rgba(16, 185, 129, 0.1) 50%, transparent 100%);
                        transform: translateX(-100%);
                        transition: transform 0.6s ease;
                        z-index: 1;
                    }
                    
                    .fi-sidebar-nav-item:hover::before {
                        transform: translateX(100%);
                    }
                    
                    .fi-sidebar-nav-item > * {
                        position: relative;
                        z-index: 2;
                    }
                    
                    .fi-sidebar-nav-item:hover {
                        background: var(--sidebar-item-hover) !important;
                        transform: translateX(-3px) !important;
                        box-shadow: 
                            0 4px 12px rgba(16, 185, 129, 0.15),
                            0 2px 4px rgba(0, 0, 0, 0.1) !important;
                    }
                    
                    [dir="rtl"] .fi-sidebar-nav-item:hover {
                        transform: translateX(3px) !important;
                    }
                    
                    /* Active nav item */
                    .fi-sidebar-nav-item[aria-current="page"],
                    .fi-sidebar-nav-item.fi-active {
                        background: var(--sidebar-item-active) !important;
                        border: 1px solid rgba(16, 185, 129, 0.2) !important;
                        box-shadow: 
                            0 0 20px rgba(16, 185, 129, 0.2),
                            inset 0 1px 0 rgba(255, 255, 255, 0.1) !important;
                    }
                    
                    .fi-sidebar-nav-item[aria-current="page"]::after,
                    .fi-sidebar-nav-item.fi-active::after {
                        content: "";
                        position: absolute;
                        right: 0;
                        top: 50%;
                        transform: translateY(-50%);
                        width: 3px;
                        height: 60%;
                        background: linear-gradient(to bottom, #10b981, #059669);
                        border-radius: 2px 0 0 2px;
                        box-shadow: 0 0 8px rgba(16, 185, 129, 0.6);
                    }
                    
                    [dir="rtl"] .fi-sidebar-nav-item[aria-current="page"]::after,
                    [dir="rtl"] .fi-sidebar-nav-item.fi-active::after {
                        right: auto;
                        left: 0;
                        border-radius: 0 2px 2px 0;
                    }
                    
                    /* Navigation text */
                    .fi-sidebar-nav-item-label {
                        font-weight: 500 !important;
                        color: rgba(255, 255, 255, 0.9) !important;
                        transition: color 0.3s ease !important;
                    }
                    
                    .fi-sidebar-nav-item:hover .fi-sidebar-nav-item-label {
                        color: rgba(255, 255, 255, 1) !important;
                        text-shadow: 0 0 8px rgba(16, 185, 129, 0.3);
                    }
                    
                    /* Navigation icons */
                    .fi-sidebar-nav-item-icon {
                        color: rgba(255, 255, 255, 0.7) !important;
                        transition: all 0.3s ease !important;
                    }
                    
                    .fi-sidebar-nav-item:hover .fi-sidebar-nav-item-icon {
                        color: #10b981 !important;
                        transform: scale(1.1) !important;
                        filter: drop-shadow(0 0 6px rgba(16, 185, 129, 0.4));
                    }
                    
                    /* Navigation badges */
                    .fi-sidebar-nav-item-badge {
                        background: linear-gradient(135deg, #10b981, #059669) !important;
                        color: white !important;
                        font-size: 0.75rem !important;
                        padding: 0.25rem 0.5rem !important;
                        border-radius: 8px !important;
                        font-weight: 600 !important;
                        box-shadow: 0 2px 4px rgba(16, 185, 129, 0.3) !important;
                        animation: pulse 2s infinite !important;
                    }
                    
                    @keyframes pulse {
                        0%, 100% { transform: scale(1); }
                        50% { transform: scale(1.05); }
                    }
                    
                    /* Navigation groups */
                    .fi-sidebar-nav-group-label {
                        color: rgba(255, 255, 255, 0.6) !important;
                        font-size: 0.75rem !important;
                        font-weight: 700 !important;
                        text-transform: uppercase !important;
                        letter-spacing: 0.05em !important;
                        margin: 1.5rem 0 0.75rem 1rem !important;
                        position: relative !important;
                    }
                    
                    .fi-sidebar-nav-group-label::after {
                        content: "";
                        position: absolute;
                        bottom: -4px;
                        left: 0;
                        width: 24px;
                        height: 2px;
                        background: linear-gradient(90deg, #10b981, transparent);
                        border-radius: 1px;
                    }
                    
                    /* Scrollbar for sidebar */
                    .fi-sidebar::-webkit-scrollbar {
                        width: 6px;
                    }
                    
                    .fi-sidebar::-webkit-scrollbar-track {
                        background: rgba(255, 255, 255, 0.05);
                        border-radius: 3px;
                    }
                    
                    .fi-sidebar::-webkit-scrollbar-thumb {
                        background: rgba(16, 185, 129, 0.3);
                        border-radius: 3px;
                        transition: background 0.3s ease;
                    }
                    
                    .fi-sidebar::-webkit-scrollbar-thumb:hover {
                        background: rgba(16, 185, 129, 0.5);
                    }
                    
                    /* Dark mode enhancements */
                    .dark .fi-sidebar {
                        --sidebar-bg: linear-gradient(145deg, #020617 0%, #0f172a 50%, #1e293b 100%);
                        --sidebar-border: rgba(148, 163, 184, 0.05);
                        --sidebar-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
                    }
                    
                    /* ========== MAIN CONTENT ADJUSTMENTS ========== */
                    .fi-main {
                        transition: all 0.3s ease !important;
                    }
                    
                    /* Content area backdrop */
                    .fi-page {
                        position: relative;
                    }
                    
                    .fi-page::before {
                        content: "";
                        position: fixed;
                        top: 0;
                        left: 0;
                        right: 0;
                        bottom: 0;
                        background: 
                            radial-gradient(circle at 20% 50%, rgba(16, 185, 129, 0.015) 0%, transparent 50%),
                            radial-gradient(circle at 80% 20%, rgba(59, 130, 246, 0.01) 0%, transparent 50%);
                        pointer-events: none;
                        z-index: -1;
                    }
                    
                    /* ========== EXISTING STYLES ========== */
                    
                    /* RTL Improvements */
                    [dir="rtl"] .fi-sidebar-nav {
                        text-align: right;
                    }
                    
                    [dir="rtl"] .fi-badge {
                        font-family: var(--font-family) !important;
                    }
                    
                    /* Better number display */
                    .filament-tables-text-column,
                    input[type="number"],
                    .fi-ta-text {
                        font-variant-numeric: tabular-nums;
                        direction: ltr;
                        text-align: left;
                    }
                    
                    [dir="rtl"] .filament-tables-text-column.text-right,
                    [dir="rtl"] input[type="number"] {
                        text-align: right;
                    }
                    
                    /* Improved form layout for RTL */
                    [dir="rtl"] .fi-fo-component-ctn {
                        text-align: right;
                    }
                    
                    /* Custom scrollbar */
                    ::-webkit-scrollbar {
                        width: 8px;
                        height: 8px;
                    }
                    
                    ::-webkit-scrollbar-track {
                        background: rgba(0, 0, 0, 0.05);
                        border-radius: 4px;
                    }
                    
                    ::-webkit-scrollbar-thumb {
                        background: rgba(0, 0, 0, 0.2);
                        border-radius: 4px;
                    }
                    
                    ::-webkit-scrollbar-thumb:hover {
                        background: rgba(0, 0, 0, 0.3);
                    }
                    
                    /* Dark mode scrollbar */
                    .dark ::-webkit-scrollbar-track {
                        background: rgba(255, 255, 255, 0.05);
                    }
                    
                    .dark ::-webkit-scrollbar-thumb {
                        background: rgba(255, 255, 255, 0.2);
                    }
                    
                    .dark ::-webkit-scrollbar-thumb:hover {
                        background: rgba(255, 255, 255, 0.3);
                    }
                    
                    /* Animations */
                    .fi-ta-row {
                        transition: all 0.2s ease;
                    }
                    
                    .fi-ta-row:hover {
                        transform: translateX(-2px);
                        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
                    }
                    
                    [dir="rtl"] .fi-ta-row:hover {
                        transform: translateX(2px);
                    }
                    
                    /* Loading states */
                    .fi-btn:disabled {
                        cursor: wait;
                    }
                    
                    /* Custom success/error colors for Persian */
                    .text-success-600 {
                        color: #16a34a !important;
                    }
                    
                    .text-danger-600 {
                        color: #dc2626 !important;
                    }
                    
                    /* Notification improvements */
                    .fi-no-notification {
                        font-size: 0.95rem;
                    }
                    
                    /* Table improvements */
                    .fi-ta-table {
                        font-size: 0.925rem;
                    }
                    
                    /* Form improvements */
                    .fi-fo-field-wrp-label {
                        font-weight: 600;
                        margin-bottom: 0.5rem;
                    }
                    
                    /* Icon alignment fix for RTL */
                    [dir="rtl"] .fi-btn-icon {
                        margin-right: 0;
                        margin-left: 0.5rem;
                    }
                    
                    /* Modal improvements */
                    .fi-modal-window {
                        animation: slideIn 0.3s ease-out;
                    }
                    
                    @keyframes slideIn {
                        from {
                            opacity: 0;
                            transform: translateY(-20px);
                        }
                        to {
                            opacity: 1;
                            transform: translateY(0);
                        }
                    }
                    
                    /* Print styles */
                    @media print {
                        .fi-sidebar,
                        .fi-topbar,
                        .fi-breadcrumbs {
                            display: none !important;
                        }
                        
                        .fi-main {
                            padding: 0 !important;
                        }
                    }
                    
                    /* Responsive adjustments */
                    @media (max-width: 768px) {
                        .fi-sidebar {
                            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3) !important;
                        }
                    }
                    
                    /* Performance optimizations */
                    .fi-sidebar-nav-item {
                        will-change: transform, background-color !important;
                    }
                    
                    /* Accessibility improvements */
                    .fi-sidebar-nav-item:focus {
                        outline: 2px solid #10b981 !important;
                        outline-offset: 2px !important;
                    }
                    
                    /* High contrast mode support */
                    @media (prefers-contrast: high) {
                        .fi-sidebar {
                            border-right: 2px solid #10b981 !important;
                        }
                        
                        .fi-sidebar-nav-item:hover {
                            border: 2px solid #10b981 !important;
                        }
                    }
                </style>'
            )
            ->renderHook(
                'panels::scripts.after',
                fn (): string => '
                <script>
                    // Set document direction
                    document.documentElement.dir = "rtl";
                    document.documentElement.lang = "fa";
                    
                    // Enhanced sidebar interactions
                    document.addEventListener("DOMContentLoaded", function() {
                        // Add smooth scrolling to sidebar
                        const sidebar = document.querySelector(".fi-sidebar");
                        if (sidebar) {
                            sidebar.style.scrollBehavior = "smooth";
                        }
                        
                        // Enhanced sidebar item interactions
                        const navItems = document.querySelectorAll(".fi-sidebar-nav-item");
                        navItems.forEach(item => {
                            // Add ripple effect on click
                            item.addEventListener("click", function(e) {
                                const ripple = document.createElement("div");
                                ripple.style.cssText = `
                                    position: absolute;
                                    border-radius: 50%;
                                    background: rgba(16, 185, 129, 0.3);
                                    transform: scale(0);
                                    animation: ripple 0.6s linear;
                                    pointer-events: none;
                                    z-index: 10;
                                `;
                                
                                const rect = item.getBoundingClientRect();
                                const size = Math.max(rect.width, rect.height);
                                const x = e.clientX - rect.left - size / 2;
                                const y = e.clientY - rect.top - size / 2;
                                
                                ripple.style.width = ripple.style.height = size + "px";
                                ripple.style.left = x + "px";
                                ripple.style.top = y + "px";
                                
                                item.style.position = "relative";
                                item.appendChild(ripple);
                                
                                setTimeout(() => {
                                    ripple.remove();
                                }, 600);
                            });
                        });
                        
                        // Add CSS for ripple animation
                        const style = document.createElement("style");
                        style.textContent = `
                            @keyframes ripple {
                                to {
                                    transform: scale(4);
                                    opacity: 0;
                                }
                            }
                        `;
                        document.head.appendChild(style);
                        
                        // Improve number input behavior
                        const numberInputs = document.querySelectorAll(\'input[type="number"]\');
                        numberInputs.forEach(input => {
                            input.addEventListener("wheel", function(e) {
                                e.preventDefault();
                            });
                        });
                        
                        // Auto-focus first input in modals
                        const observer = new MutationObserver(function(mutations) {
                            mutations.forEach(function(mutation) {
                                if (mutation.type === "childList") {
                                    const modal = document.querySelector(".fi-modal-open");
                                    if (modal) {
                                        setTimeout(() => {
                                            const firstInput = modal.querySelector("input:not([type=hidden]), textarea, select");
                                            if (firstInput) {
                                                firstInput.focus();
                                            }
                                        }, 100);
                                    }
                                }
                            });
                        });
                        
                        observer.observe(document.body, {
                            childList: true,
                            subtree: true
                        });
                        
                        // Improve table row click area
                        document.addEventListener("click", function(e) {
                            const row = e.target.closest(".fi-ta-row");
                            if (row && !e.target.closest("button, a, input, select, textarea")) {
                                const editButton = row.querySelector(".fi-ta-edit-action");
                                if (editButton) {
                                    editButton.click();
                                }
                            }
                        });
                    });
                    
                    // Add keyboard shortcuts
                    document.addEventListener("keydown", function(e) {
                        // Ctrl/Cmd + S to save forms
                        if ((e.ctrlKey || e.metaKey) && e.key === "s") {
                            e.preventDefault();
                            const submitButton = document.querySelector(\'button[type="submit"]:not(:disabled)\');
                            if (submitButton) {
                                submitButton.click();
                            }
                        }
                        
                        // Escape to close modals
                        if (e.key === "Escape") {
                            const closeButton = document.querySelector(".fi-modal-close-btn");
                            if (closeButton) {
                                closeButton.click();
                            }
                        }
                    });
                    
                    // Notification sound (optional)
                    window.playNotificationSound = function() {
                        const audio = new Audio("data:audio/wav;base64,UklGRlQFAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YTAFAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAACgAAC");
                        audio.volume = 0.2;
                        audio.play();
                    };
                </script>'
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
                \App\Filament\Pages\ConnectionTest::class,
                \App\Filament\Pages\GridCalculator::class,
                \App\Filament\Pages\Notes::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                // Widgets\AccountWidget::class, // Commented out for cleaner dashboard
                // Widgets\FilamentInfoWidget::class, // Commented out for cleaner dashboard
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->authGuard('web')
            ->tenant(null)
            ->spa()
            ->bootUsing(function () {
                // Custom boot logic if needed
            })
            ->globalSearch(true)
            ->globalSearchFieldSuffix('جستجو...')
            ->breadcrumbs(true)
            ->darkMode(true)
            ->topbar(true)
            ->navigation(true);
    }
}