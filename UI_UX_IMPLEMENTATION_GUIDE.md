# GovQueue UI/UX Implementation Guide

## Status: Medium & Low Priority Issues

This document contains detailed implementation instructions for the remaining UI/UX improvements identified in the GovQueue system analysis.

---

## ✅ Completed (High Priority)

- ✅ **Issue #1**: Visual feedback on slot selection (IMPLEMENTED)
- ✅ **Issue #2**: Manual retry button for queue status errors (IMPLEMENTED)
- ✅ **Issue #3**: Loading states for staff console actions (IMPLEMENTED)

---

## 🟡 Medium Priority Issues - TO IMPLEMENT

### Issue #4: Public Display - Improved Offline Warning

**Location:** `resources/views/livewire/display/public-queue-display.blade.php`

**Problem:** When WebSocket disconnects, stale data is shown without prominent warning.

**Implementation:**

```blade
{{-- Add this right after the header, before main content --}}
<div x-show="!connected" 
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 transform -translate-y-2"
     class="bg-amber-500 text-white px-6 py-4 text-center border-b-4 border-amber-600">
    <div class="mx-auto max-w-4xl">
        <p class="text-lg font-bold">⚠️ Connection interrupted</p>
        <p class="mt-1 text-sm">
            The queue display may be outdated. Last updated: {{ $lastUpdatedAt }}
        </p>
        <p class="mt-1 text-xs opacity-90">
            The display will update automatically when the connection is restored.
        </p>
    </div>
</div>
```

**Testing:**
1. Open public display in browser
2. Open DevTools → Network tab
3. Throttle to "Offline"
4. Verify warning appears prominently
5. Restore connection and verify warning disappears

---

### Issue #5: Booking Form - Progressive Disclosure for Priority Reference

**Location:** `resources/views/client/booking/create.blade.php`

**Problem:** Priority reference field is always visible even when not needed.

**Implementation:**

Replace the priority claim fieldset with:

```blade
<fieldset class="rounded-xl border border-amber-200 bg-amber-50 p-6"
          x-data="{ 
              needsRef: false,
              updateRefRequired() {
                  const pwdChecked = document.querySelector('input[name=claims_pwd]')?.checked || false;
                  const pregnantChecked = document.querySelector('input[name=claims_pregnant]')?.checked || false;
                  this.needsRef = pwdChecked || pregnantChecked;
              }
          }">
    <legend class="px-1 text-sm font-bold text-amber-900">
        2. Priority claim <span class="font-normal text-amber-700">(optional)</span>
    </legend>

    <p class="text-sm text-amber-900">
        Claiming priority here does <strong>not</strong> move you up the queue yet.
        Staff must verify your claim at the counter first.
    </p>

    <div class="mt-4 space-y-3">
        @foreach ($priorities as $key => $meta)
            <label class="flex items-start gap-3 text-sm text-slate-800
                          {{ $meta['available'] ? '' : 'opacity-50' }}">
                <input type="checkbox" 
                       name="claims_{{ $key }}" 
                       value="1"
                       @disabled(! $meta['available'])
                       @checked(old('claims_'.$key))
                       @if($meta['requires_reference'])
                           @change="updateRefRequired()"
                       @endif
                       class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-500">
                <span>
                    <span class="font-medium">{{ $meta['label'] }}</span>
                    <span class="ml-1 text-xs text-slate-500">({{ $meta['basis'] }})</span>
                    @if ($key === 'senior_citizen')
                        <span class="block text-xs text-slate-600">
                            @if ($meta['available'])
                                Recognised from your date of birth.
                            @else
                                Your date of birth does not yet meet the RA 9994 threshold of
                                {{ config('govqueue.senior_citizen_age') }}.
                            @endif
                        </span>
                    @endif
                </span>
            </label>
        @endforeach

        {{-- Progressive disclosure: only show when PWD or Pregnant is checked --}}
        <div x-show="needsRef" 
             x-cloak
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 transform -translate-y-2"
             x-transition:enter-end="opacity-100 transform translate-y-0">
            <label for="priority_reference" class="block text-sm font-medium text-slate-700">
                Supporting reference
                <span class="text-rose-600">*</span>
            </label>
            <input id="priority_reference" 
                   name="priority_reference" 
                   type="text"
                   placeholder="e.g. PWD-1234567 or clinic referral no."
                   value="{{ old('priority_reference') }}"
                   :required="needsRef"
                   class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
            <p class="mt-1 text-xs text-amber-700">
                Required to verify your PWD or pregnant priority claim.
            </p>
            @error('priority_reference')
                <p class="mt-1 text-sm font-medium text-rose-700">{{ $message }}</p>
            @enderror
        </div>
    </div>
</fieldset>
```

**Testing:**
1. Load booking form
2. Verify reference field is hidden initially
3. Check "Person with disability" checkbox
4. Verify reference field appears with smooth transition
5. Uncheck the box and verify field disappears
6. Check "Pregnant client" checkbox
7. Verify reference field appears
8. Submit form without reference and verify validation error

---

### Issue #6: Keyboard Shortcuts for Staff Console

**Location:** Create new file `resources/js/staff-shortcuts.js`

**Problem:** No keyboard shortcuts for repetitive staff actions.

**Implementation:**

```javascript
/**
 * Keyboard shortcuts for the staff queue console.
 * 
 * Shortcuts:
 * - Ctrl+N (or Cmd+N on Mac): Call next client
 * - Ctrl+C (or Cmd+C): Complete transaction (when serving)
 * - Ctrl+S (or Cmd+S): Skip current client (when serving)
 * - Escape: Close any open modal/panel
 */
document.addEventListener('DOMContentLoaded', () => {
    // Only activate on staff console pages
    const consoleElement = document.querySelector('[data-window-code]');
    if (!consoleElement) return;

    const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
    const modifierKey = isMac ? 'metaKey' : 'ctrlKey';

    document.addEventListener('keydown', (e) => {
        // Don't trigger shortcuts when user is typing in an input
        if (e.target.matches('input, textarea, select')) {
            return;
        }

        // Call next: Ctrl/Cmd + N
        if (e[modifierKey] && e.key.toLowerCase() === 'n') {
            e.preventDefault();
            const callButton = document.querySelector('button[wire\\:click="callNext"]');
            if (callButton && !callButton.disabled) {
                callButton.click();
            }
        }

        // Complete: Ctrl/Cmd + Enter (when transaction panel is open)
        if (e[modifierKey] && e.key === 'Enter') {
            e.preventDefault();
            const completeButton = document.querySelector('button[wire\\:click="complete"]');
            if (completeButton && !completeButton.disabled) {
                completeButton.click();
            }
        }

        // Skip: Ctrl/Cmd + S
        if (e[modifierKey] && e.key.toLowerCase() === 's') {
            e.preventDefault();
            const skipButton = document.querySelector('button[wire\\:click*="skip"]');
            if (skipButton && !skipButton.disabled) {
                skipButton.click();
            }
        }

        // Close modal: Escape
        if (e.key === 'Escape') {
            const closeButton = document.querySelector('[x-show] button[type="button"]');
            if (closeButton) {
                closeButton.click();
            }
        }
    });

    // Show keyboard shortcuts hint
    console.info('Keyboard shortcuts enabled:\n' +
                 '  Call next: ' + (isMac ? 'Cmd' : 'Ctrl') + '+N\n' +
                 '  Complete: ' + (isMac ? 'Cmd' : 'Ctrl') + '+Enter\n' +
                 '  Skip: ' + (isMac ? 'Cmd' : 'Ctrl') + '+S\n' +
                 '  Close: Escape');
});
```

**Update `resources/js/app.js`:**

```javascript
import './bootstrap';
import './echo';
import './govqueue';
import './staff-shortcuts'; // Add this line
```

**Update staff console template to show shortcuts:**

Add this helper element near the stats:

```blade
<div class="rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-800">
    <details class="cursor-pointer">
        <summary class="font-semibold">⌨️ Shortcuts</summary>
        <dl class="mt-2 space-y-1">
            <div class="flex justify-between gap-4">
                <dt>Call next:</dt>
                <dd class="font-mono"><kbd>Ctrl+N</kbd></dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt>Complete:</dt>
                <dd class="font-mono"><kbd>Ctrl+Enter</kbd></dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt>Skip:</dt>
                <dd class="font-mono"><kbd>Ctrl+S</kbd></dd>
            </div>
        </dl>
    </details>
</div>
```

**Testing:**
1. Open staff console
2. Press Ctrl+N (or Cmd+N on Mac)
3. Verify "Call next" is triggered
4. When serving a client, press Ctrl+S
5. Verify skip action is triggered
6. Test that shortcuts don't work when typing in input fields

---

### Issue #7: Confirmation Dialog for Cancel Appointment

**Location:** `resources/views/client/appointments/show.blade.php` (or wherever cancel button is)

**Problem:** No confirmation when canceling appointments.

**Implementation Option 1 - Alpine.js (Recommended):**

```blade
<div x-data="{ showCancelModal: false }">
    <button @click="showCancelModal = true" 
            type="button"
            class="rounded-lg border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
        Cancel appointment
    </button>

    {{-- Cancel confirmation modal --}}
    <div x-show="showCancelModal" 
         x-cloak
         @keydown.escape.window="showCancelModal = false"
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100">
        
        <div @click.away="showCancelModal = false"
             class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 transform scale-95"
             x-transition:enter-end="opacity-100 transform scale-100">
            
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0 rounded-full bg-rose-100 p-3">
                    <svg class="h-6 w-6 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <div class="flex-1">
                    <h3 class="text-lg font-bold text-slate-900">Cancel appointment?</h3>
                    <p class="mt-2 text-sm text-slate-600">
                        This action cannot be undone. Your slot will be released and made available to other clients.
                    </p>
                    <p class="mt-2 text-sm font-medium text-slate-700">
                        Queue number: <span class="font-mono">{{ $appointment->queue_number }}</span>
                    </p>
                </div>
            </div>

            <form method="POST" action="{{ route('client.appointments.cancel', $appointment) }}" class="mt-6">
                @csrf
                
                <label for="cancel_reason" class="block text-sm font-medium text-slate-700">
                    Reason for cancellation <span class="text-slate-500">(optional)</span>
                </label>
                <textarea id="cancel_reason" 
                          name="reason" 
                          rows="3"
                          placeholder="e.g., Schedule conflict, emergency"
                          class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"></textarea>

                <div class="mt-4 flex gap-3">
                    <button type="button" 
                            @click="showCancelModal = false"
                            class="flex-1 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Keep appointment
                    </button>
                    <button type="submit"
                            class="flex-1 rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-rose-700">
                        Yes, cancel it
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
```

**Implementation Option 2 - JavaScript confirm() (Simpler):**

```blade
<form method="POST" 
      action="{{ route('client.appointments.cancel', $appointment) }}"
      onsubmit="return confirm('Are you sure you want to cancel this appointment? This cannot be undone.');">
    @csrf
    <input type="hidden" name="reason" value="User-initiated cancellation">
    <button type="submit"
            class="rounded-lg border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
        Cancel appointment
    </button>
</form>
```

**Testing:**
1. Navigate to appointment details page
2. Click "Cancel appointment"
3. Verify modal/dialog appears
4. Click "Keep appointment" or press Escape
5. Verify modal closes without canceling
6. Click "Cancel appointment" again
7. Click "Yes, cancel it"
8. Verify appointment is canceled and user is redirected

---

## 🟢 Low Priority Issues - TO IMPLEMENT

### Issue #8: Mobile Menu UX - Improved Details Element Behavior

**Location:** `resources/js/app.js`

**Current code:**
```javascript
document.addEventListener('click', (event) => {
    document.querySelectorAll('details[open]').forEach((el) => {
        if (!el.contains(event.target)) {
            el.removeAttribute('open');
        }
    });
});
```

**Improved code:**
```javascript
document.addEventListener('click', (event) => {
    // Don't close menu if clicking on the summary itself (let browser handle toggle)
    const clickedSummary = event.target.closest('summary');
    if (clickedSummary) return;

    // Close all open details when clicking outside
    document.querySelectorAll('details[open]').forEach((el) => {
        if (!el.contains(event.target)) {
            el.removeAttribute('open');
        }
    });
});

// Add keyboard support: Escape to close
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        document.querySelectorAll('details[open]').forEach((el) => {
            el.removeAttribute('open');
        });
    }
});
```

**Testing:**
1. Open mobile menu (resize browser to mobile width)
2. Click summary to open menu
3. Click summary again - should toggle properly
4. Open menu and press Escape
5. Verify menu closes
6. Open menu and click outside
7. Verify menu closes

---

### Issue #9: Focus Management After Livewire Actions

**Location:** Create new file `resources/js/livewire-focus.js`

**Problem:** Focus is lost after Livewire state changes, poor for keyboard users.

**Implementation:**

```javascript
/**
 * Manage focus after Livewire updates for better accessibility.
 */
document.addEventListener('livewire:init', () => {
    // After any Livewire action completes, focus on feedback messages
    Livewire.hook('morph.updated', ({ el, component }) => {
        // Focus on success/error messages (role="status" or role="alert")
        const feedback = el.querySelector('[role="status"], [role="alert"]');
        if (feedback && !feedback.contains(document.activeElement)) {
            // Make focusable temporarily
            feedback.setAttribute('tabindex', '-1');
            feedback.focus({ preventScroll: false });
            
            // Remove tabindex after focus (it's not needed for normal navigation)
            feedback.addEventListener('blur', () => {
                feedback.removeAttribute('tabindex');
            }, { once: true });
        }
    });

    // After component load, announce to screen readers
    Livewire.hook('component.init', ({ component }) => {
        const el = component.el ?? component.root;
        
        // Add live region for dynamic updates
        if (!el.hasAttribute('aria-live')) {
            el.setAttribute('aria-live', 'polite');
            el.setAttribute('aria-atomic', 'false');
        }
    });
});

// Add global skip links for keyboard navigation
document.addEventListener('DOMContentLoaded', () => {
    // Check if skip links already exist
    if (document.querySelector('.skip-links')) return;

    const skipLinks = document.createElement('div');
    skipLinks.className = 'skip-links';
    skipLinks.innerHTML = `
        <a href="#main-content" class="skip-link">Skip to main content</a>
        <a href="#nav" class="skip-link">Skip to navigation</a>
    `;
    
    document.body.prepend(skipLinks);
});
```

**Add CSS in `resources/css/app.css`:**

```css
/* Skip links for keyboard navigation */
.skip-links {
    position: relative;
}

.skip-link {
    position: absolute;
    left: -9999px;
    top: 0;
    z-index: 9999;
    padding: 0.75rem 1.5rem;
    background: #1e40af;
    color: white;
    font-weight: 600;
    text-decoration: none;
    border-radius: 0 0 0.5rem 0.5rem;
}

.skip-link:focus {
    left: 0.5rem;
}
```

**Update `resources/js/app.js`:**
```javascript
import './bootstrap';
import './echo';
import './govqueue';
import './staff-shortcuts';
import './livewire-focus'; // Add this line
```

**Testing:**
1. Use keyboard only (Tab, Enter, Space)
2. Navigate to staff console
3. Press Tab - should see "Skip to main content" link
4. Press Enter on skip link - should jump to main content
5. Call next client
6. Verify focus moves to feedback message
7. Use screen reader (NVDA/JAWS) to verify announcements

---

### Issue #10: Queue Number Display - Responsive Sizing

**Location:** `resources/views/livewire/display/public-queue-display.blade.php`

**Current code:**
```blade
<p class="text-7xl font-black tracking-tight sm:text-8xl">
    {{ $this->serving->queue_number }}
</p>
```

**Improved code:**
```blade
@php
    $queueNumber = $this->serving->queue_number;
    $numberLength = strlen($queueNumber);
    $sizeClasses = match(true) {
        $numberLength <= 5 => 'text-7xl sm:text-8xl lg:text-9xl',
        $numberLength <= 7 => 'text-6xl sm:text-7xl lg:text-8xl',
        default => 'text-5xl sm:text-6xl lg:text-7xl'
    };
@endphp

<p class="font-black tracking-tight {{ $sizeClasses }} break-words">
    {{ $queueNumber }}
</p>
```

**Also update the "Next in line" section:**
```blade
@foreach ($upNext as $index => $appointment)
    @php
        $numberLength = strlen($appointment->queue_number);
        $cardSizeClasses = match(true) {
            $numberLength <= 5 => 'text-4xl',
            $numberLength <= 7 => 'text-3xl',
            default => 'text-2xl'
        };
    @endphp
    
    <li @class([
        'rounded-2xl border px-5 py-5 text-center',
        'border-sky-500/60 bg-sky-500/10' => $index === 0,
        'border-slate-800 bg-slate-900' => $index !== 0,
    ])>
        <p class="font-black tracking-tight break-words {{ $cardSizeClasses }}">
            {{ $appointment->queue_number }}
        </p>
        <p class="mt-1 text-xs uppercase tracking-wider text-slate-400">
            {{ $index === 0 ? 'Next' : '#'.($index + 1) }}
        </p>
    </li>
@endforeach
```

**Testing:**
1. Generate queue numbers with different lengths:
   - Short: "A-001"
   - Medium: "A-12345"
   - Long: "WALK-001"
2. Load public display for each
3. Verify text scales appropriately
4. Test on different screen sizes (mobile, tablet, desktop, TV display)
5. Verify no text overflow occurs

---

## 📋 Additional Improvements (Future Consideration)

### Touch Target Size Audit

**Issue:** Some buttons may be too small for comfortable touch interaction.

**Minimum Requirements:**
- iOS: 44px × 44px
- Android: 48dp × 48dp (≈ 48px)

**How to audit:**
```javascript
// Run this in browser console to find small touch targets
document.querySelectorAll('button, a, input[type="checkbox"], input[type="radio"]').forEach(el => {
    const rect = el.getBoundingClientRect();
    if (rect.width < 44 || rect.height < 44) {
        console.warn('Small touch target:', el, `${rect.width}×${rect.height}`);
        el.style.outline = '2px solid red';
    }
});
```

**Fix example:**
```blade
{{-- Before --}}
<button class="px-2 py-1 text-xs">Action</button>

{{-- After --}}
<button class="px-4 py-2.5 text-sm min-h-[44px]">Action</button>
```

---

### Color Contrast Audit

**Requirement:** WCAG AA requires 4.5:1 for normal text, 3:1 for large text.

**Tools:**
- Browser DevTools → Lighthouse → Accessibility audit
- Online: [WebAIM Contrast Checker](https://webaim.org/resources/contrastchecker/)
- Extension: [axe DevTools](https://www.deque.com/axe/devtools/)

**Common issues to check:**
```blade
{{-- Potential contrast issues --}}
<p class="text-slate-400">Low contrast</p> {{-- Check on white bg --}}
<span class="text-blue-500 bg-blue-50">Button</span> {{-- Check contrast --}}
```

**Fix example:**
```blade
{{-- Before: text-slate-400 on white may fail --}}
<p class="text-slate-400">Label</p>

{{-- After: text-slate-600 has better contrast --}}
<p class="text-slate-600">Label</p>
```

---

### Service Worker for Offline Support

**Location:** Create `public/service-worker.js`

**Basic implementation:**

```javascript
const CACHE_NAME = 'govqueue-v1';
const OFFLINE_URL = '/offline.html';

const STATIC_ASSETS = [
    '/',
    '/offline.html',
    '/build/assets/app.css',
    '/build/assets/app.js',
];

// Install service worker
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS);
        })
    );
});

// Serve from cache, fall back to network
self.addEventListener('fetch', (event) => {
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(() => {
                return caches.match(OFFLINE_URL);
            })
        );
    } else {
        event.respondWith(
            caches.match(event.request).then((response) => {
                return response || fetch(event.request);
            })
        );
    }
});
```

**Register in `resources/views/layouts/app.blade.php`:**
```blade
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/service-worker.js')
        .then(reg => console.log('Service worker registered'))
        .catch(err => console.error('Service worker registration failed:', err));
}
</script>
```

**Create offline page `resources/views/offline.blade.php`:**
```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offline - GovQueue</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            background: #f8fafc;
        }
        .offline-message {
            text-align: center;
            padding: 2rem;
        }
        h1 { color: #1e293b; }
        p { color: #64748b; }
    </style>
</head>
<body>
    <div class="offline-message">
        <h1>📡 You're offline</h1>
        <p>Please check your internet connection and try again.</p>
        <button onclick="location.reload()">Retry</button>
    </div>
</body>
</html>
```

---

## 🧪 Complete Testing Checklist

### Manual Testing

- [ ] **Booking flow**: Complete end-to-end booking on mobile and desktop
- [ ] **Queue status**: Test refresh, error recovery, WebSocket updates
- [ ] **Staff console**: Test all actions with loading states
- [ ] **Public display**: Test offline warning, connection indicator
- [ ] **Priority claims**: Test progressive disclosure
- [ ] **Keyboard navigation**: Tab through entire app, test shortcuts
- [ ] **Cancel flow**: Test confirmation dialog, reason submission
- [ ] **Mobile menu**: Test open/close behavior
- [ ] **Touch targets**: Verify all interactive elements are 44px+ on mobile
- [ ] **Color contrast**: Run Lighthouse accessibility audit
- [ ] **Screen reader**: Test with NVDA (Windows) or VoiceOver (Mac)
- [ ] **Form validation**: Test all error states and recovery
- [ ] **Loading states**: Throttle network and verify all actions show loading

### Browser Testing

- [ ] Chrome (latest)
- [ ] Firefox (latest)
- [ ] Safari (desktop & iOS)
- [ ] Edge (latest)
- [ ] Chrome Android

### Device Testing

- [ ] iPhone (iOS Safari)
- [ ] Android phone (Chrome)
- [ ] iPad (Safari)
- [ ] Desktop 1920×1080
- [ ] Desktop 1366×768
- [ ] Mobile 375×667 (iPhone SE)

### Accessibility Testing

- [ ] Keyboard only navigation
- [ ] Screen reader (NVDA/JAWS/VoiceOver)
- [ ] Color contrast (WebAIM checker)
- [ ] Focus indicators visible
- [ ] Skip links functional
- [ ] ARIA labels correct
- [ ] Form labels associated
- [ ] Error messages announced

---

## 📚 Resources

### Documentation
- [Tailwind CSS](https://tailwindcss.com/docs)
- [Alpine.js](https://alpinejs.dev/)
- [Livewire](https://livewire.laravel.com/)
- [WCAG 2.1 Guidelines](https://www.w3.org/WAI/WCAG21/quickref/)

### Tools
- [Lighthouse](https://developer.chrome.com/docs/lighthouse) - Accessibility audit
- [axe DevTools](https://www.deque.com/axe/devtools/) - Accessibility testing
- [WAVE](https://wave.webaim.org/) - Web accessibility evaluation
- [WebAIM Contrast Checker](https://webaim.org/resources/contrastchecker/)

### Testing
- [NVDA Screen Reader](https://www.nvaccess.org/) - Free for Windows
- [VoiceOver](https://www.apple.com/accessibility/voiceover/) - Built into macOS/iOS
- [BrowserStack](https://www.browserstack.com/) - Cross-browser testing

---

## 💡 Implementation Priority Order

1. **Issue #4**: Public display offline warning (30 minutes)
2. **Issue #5**: Progressive disclosure priority field (45 minutes)
3. **Issue #7**: Cancel confirmation dialog (1 hour)
4. **Issue #6**: Keyboard shortcuts (2 hours)
5. **Issue #8**: Mobile menu improvements (30 minutes)
6. **Issue #9**: Focus management (1.5 hours)
7. **Issue #10**: Responsive queue numbers (30 minutes)
8. **Touch target audit**: Review and fix (2 hours)
9. **Color contrast audit**: Review and fix (1 hour)
10. **Service worker**: Offline support (3 hours)

**Total estimated time: ~12-15 hours**

---

## ✅ Acceptance Criteria

Each implementation should meet these criteria:

- [ ] Code follows existing project conventions
- [ ] Works on all target browsers
- [ ] Passes Lighthouse accessibility audit (score 90+)
- [ ] Tested on mobile and desktop
- [ ] Keyboard navigation works correctly
- [ ] No console errors
- [ ] Loading states provide clear feedback
- [ ] Error states allow recovery
- [ ] Changes are documented in this file

---

## 📞 Support

If you encounter issues during implementation:

1. Check browser console for errors
2. Review Livewire/Alpine.js documentation
3. Test in incognito mode (disable extensions)
4. Clear browser cache and rebuild assets: `npm run build`
5. Check Laravel logs: `storage/logs/laravel.log`

---

*Last updated: Based on analysis completed on [Current Date]*
*Status: High priority issues implemented, medium/low priority documented*
