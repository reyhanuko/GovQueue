@props([
    'href' => '#',
    'active' => false,
    'exact' => false,
])

{{--
    One navigation link, with the "you are here" state built in.

    The active state was missing from every menu in the app, so on a page with
    a sub-navigation (Reports, for instance) there was nothing to say which of
    eight links you were already on. Two visual signals rather than one, because
    colour alone does not survive a greyscale print or a colour-blind reader:

      - aria-current="page" for assistive technology
      - font-semibold text-blue-700 for everyone else

    Matching is by route *name pattern*, not by path, so query strings, the
    locale prefix and trailing slashes cannot knock the highlight out.
--}}

<a href="{{ $href }}"
   @if ($active) aria-current="page" @endif
   @class([
       'block rounded-lg px-3 py-2 transition-colors',
       'bg-blue-50 font-semibold text-blue-800' => $active,
       'text-slate-700 hover:bg-slate-50 hover:text-slate-900' => ! $active,
   ])
   {{ $attributes }}>{{ $slot }}</a>
