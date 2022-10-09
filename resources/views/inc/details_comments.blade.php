@if (auth()->user() &&
            (auth()->user()->roles()->first()->role != 'Website' && config('yarm.comments') == True))
    <div class="card mt-3">
        <h4 class="card-header">@lang('Comments')</h4>
        <div class="card-body">

            @comments(['model' => $ref])

        </div>
    </div>
@endif
