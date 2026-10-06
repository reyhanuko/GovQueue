{{-- Small amber "Unsaved changes" pill, shared by every form that tracks
     edits. Two ways to flip it on:
       - Livewire forms: include inside the component and set the wire:dirty
         binding inline on the <p> (see queue console panels).
       - Plain HTML forms: include as-is; the document listener in
         resources/js/app.js flips data-unsaved when any input changes. --}}
<p class="mt-3 text-xs font-medium text-amber-700 opacity-0 transition-opacity"
   data-unsaved>
    Unsaved changes
</p>
