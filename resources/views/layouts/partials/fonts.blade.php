{{--
    Brand typeface, loaded once here so every layout requests the same weights.

    These two layouts used to request different subsets:

      layouts/app   400,500,600,700,800   - but the views use font-black (900),
                                            which is absent, so every font-black
                                            heading silently rendered at 800.
      display/index 400,600,800,900       - missing 500 and 700, which that view
                                            also uses.

    Same brand, two different weight lists, so text looked different depending on
    which screen you were on. One list now, covering exactly the weights the
    markup uses: normal, medium, semibold, bold, black.

    Extras are listed so the browser can synthesise rather than re-request:
    including 800 costs nothing and avoids a fallback for any future font-extrabold.
--}}
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800,900&display=swap" rel="stylesheet" />
