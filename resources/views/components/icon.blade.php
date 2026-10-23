@props([
    'name' => '',
])

{{--
    A decorative icon.

    The path data is passed in rather than looked up here, so the set that a
    navigation group uses stays visible in one place next to the labels it
    belongs to, instead of being split across a component and its callers.

    Always aria-hidden. Every icon in this application sits beside a text label
    that already says the same thing, so announcing it would just repeat the
    label - and "icon" tells a screen reader user less than the word does. Size
    and colour follow the text beside it, so the icon never becomes the loudest
    thing in the row.
--}}
<svg class="h-4 w-4 shrink-0 text-current opacity-70"
     viewBox="0 0 24 24"
     fill="none"
     stroke="currentColor"
     stroke-width="1.6"
     stroke-linecap="round"
     stroke-linejoin="round"
     aria-hidden="true"
     focusable="false">{!! $name !!}</svg>