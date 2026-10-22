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

    The active state now carries a solid left edge in addition to the tint and
    the weight. A pale background alone sat at roughly 1.1:1 against the page
    behind it - visible only if you already knew where to look, which is not
    what "where am I" needs. The bar is a shape rather than a colour, so it
    survives a greyscale print and does not depend on telling blue from grey.
    It is inset with a negative margin so it sits flush against the column edge
    without pushing the label out of line with its neighbours.

    Hover and active no longer fight: an active link keeps its own colours and
    simply stops shifting under the pointer.
--}}

<a href="{{ $href }}"
   @if ($active) aria-current="page" @endif
   @class([
       'relative flex items-center gap-2.5 rounded-lg py-2 pl-4 pr-3 transition-colors',
       'bg-blue-50 font-semibold text-blue-900 before:absolute before:inset-y-1 before:left-0 before:w-1 before:rounded-full before:bg-blue-700' => $active,
       'text-slate-700 hover:bg-slate-100 hover:text-slate-900' => ! $active,
   ])
   {{ $attributes }}>{{ $slot }}</a>
