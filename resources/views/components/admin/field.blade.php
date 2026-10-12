@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'help' => null,
    'required' => false,
    'step' => null,
    'min' => null,
    'max' => null,
    'placeholder' => null,
    // Overrides the element id, which also drives the help and error ids.
    // Needed whenever a page renders the same field in more than one form:
    // without it every copy shares an id, so label[for] and aria-describedby
    // silently resolve to whichever one appears first in the document.
    'id' => null,
    // Whether to prefer old() over the passed value. old() is global rather than
    // per-form, so on a page with several independent forms - one per slot
    // template - only the form that was actually submitted should repopulate.
    // The others must show their stored values or they would display another
    // record's rejected input as if it were their own.
    'useOld' => true,
])

@php
    // old() wins so a rejected submission keeps what the administrator typed;
    // otherwise the stored value, otherwise whatever the caller passed.
    $current = $useOld ? old($name, $value) : $value;
    $fieldId = $id ?? $name;
    $hasError = $useOld && $errors->has($name);
    $describedBy = collect([
        $hasError ? $fieldId.'-error' : null,
        $help ? $fieldId.'-help' : null,
    ])->filter()->implode(' ');
    // Callers can make a control inert - the last-administrator and
    // self-demotion guards do. A disabled field is not submitted, which is why
    // those rules are duplicated in the service layer: this is a convenience,
    // the service is the rule.
    $isDisabled = (bool) $attributes->get('disabled');

    /*
     * Times come out of MySQL as "08:00:00" (a TIME column) but an
     * <input type="time"> is specified in HH:MM. Handing it the seconds form
     * made the browser submit an empty value, which then failed the HH:MM
     * validation on every save - an error the administrator could do nothing
     * about, on a form they had never got wrong. Normalised here so every
     * caller gets it right.
     */
    if ($type === 'time' && is_string($current)) {
        $current = preg_match('/^\d{2}:\d{2}/', $current, $m) ? $m[0] : $current;
    }
@endphp

<div>
    <label for="{{ $fieldId }}" class="block text-sm font-medium text-slate-700">
        {{ $label }}
        @if ($required)
            <span class="text-rose-600" aria-hidden="true">*</span>
            <span class="sr-only">(required)</span>
        @endif
    </label>

    @if ($type === 'textarea')
        <textarea id="{{ $fieldId }}" name="{{ $name }}" rows="3"
                  @if ($required) required @endif
                  @disabled($isDisabled)
                  @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
                  @if ($hasError) aria-invalid="true" @endif
                  placeholder="{{ $placeholder }}"
                  @class([
                      'mt-1 w-full rounded-lg border px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1',
                      'cursor-not-allowed bg-slate-100 text-slate-500' => $isDisabled,
                      'border-rose-400 focus:border-rose-500 focus:ring-rose-500' => $hasError && ! $isDisabled,
                      'border-slate-300 focus:border-blue-500 focus:ring-blue-500' => ! $hasError && ! $isDisabled,
                  ])>{{ $current }}</textarea>
    @elseif ($type === 'select')
        <select id="{{ $fieldId }}" name="{{ $name }}"
                @if ($required) required @endif
                @disabled($isDisabled)
                @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
                @if ($hasError) aria-invalid="true" @endif
                @class([
                    'mt-1 w-full rounded-lg border px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1',
                    'cursor-not-allowed bg-slate-100 text-slate-500' => $isDisabled,
                    'border-rose-400 focus:border-rose-500 focus:ring-rose-500' => $hasError && ! $isDisabled,
                    'border-slate-300 focus:ring-blue-500 focus:ring-blue-500' => ! $hasError && ! $isDisabled,
                ])>
            {{ $slot }}
        </select>
    @else
        <input id="{{ $fieldId }}" name="{{ $name }}" type="{{ $type }}"
               value="{{ $type === 'password' ? '' : $current }}"
               @if ($required) required @endif
               @disabled($isDisabled)
               @if ($step !== null) step="{{ $step }}" @endif
               @if ($min !== null) min="{{ $min }}" @endif
               @if ($max !== null) max="{{ $max }}" @endif
               @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
               @if ($hasError) aria-invalid="true" @endif
               placeholder="{{ $placeholder }}"
               @class([
                   'mt-1 w-full rounded-lg border px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1',
                   'cursor-not-allowed bg-slate-100 text-slate-500' => $isDisabled,
                   'border-rose-400 focus:border-rose-500 focus:ring-rose-500' => $hasError && ! $isDisabled,
                   'border-slate-300 focus:border-blue-500 focus:ring-blue-500' => ! $hasError && ! $isDisabled,
               ])>
    @endif

    @if ($help)
        <p id="{{ $fieldId }}-help" class="mt-1 text-xs text-slate-500">{{ $help }}</p>
    @endif

    {{-- aria-live so the message is announced when it appears after a submit. --}}
    @error($name)
        <p id="{{ $fieldId }}-error" role="alert" class="mt-1 text-sm font-medium text-rose-700">{{ $message }}</p>
    @enderror
</div>