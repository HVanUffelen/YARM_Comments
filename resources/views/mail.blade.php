{{-- TODO lang --}}
@component('mail::message')
# {{ __('Dear') . ' ' . $data['name'] }}

{{ $data['subject'] . ':' }}

@component('mail::panel')
    {{ $data['message'] }}
@endcomponent

@component('mail::button', ['url' => $data['url']])
    {{ __('View Comment') }}
@endcomponent

<div style="text-align: center !important;">{!! 'DLBT - Universität Wien - ' . date("Y") . ' &#169;.' !!}</div>
@endcomponent
