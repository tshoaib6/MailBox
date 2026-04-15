@php
    $sendStatus = $message->send_status ?? null;
    $smtpCode = $message->smtp_code ?? null;
    $smtpStatus = $message->smtp_status ?? null;
    $detail = $message->error_detail ?: ($message->smtp_message ?? null);
@endphp

@if ($sendStatus === 'rejected')
    <div class="badge badge-danger">{{ __('Rejected') }}</div>
@elseif ($sendStatus === 'error')
    <div class="badge badge-warning">{{ __('Error') }}</div>
@elseif ($message->bounced_at)
    <div class="badge badge-danger">{{ __('Bounced') }}</div>
@elseif ($message->unsubscribed_at)
    <div class="badge badge-danger">{{ __('Unsubscribed') }}</div>
@elseif ($message->clicked_at)
    <div class="badge badge-success">{{ __('Clicked') }}</div>
@elseif ($message->opened_at)
    <div class="badge badge-success">{{ __('Opened') }}</div>
@elseif ($sendStatus === 'delivered' || $message->delivered_at)
    <div class="badge badge-info">{{ __('Delivered') }}</div>
@elseif ($message->sent_at)
    <div class="badge badge-light">{{ __('Sent') }}</div>
@else
    <div class="badge badge-light">{{ __('Draft') }}</div>
@endif

@if ($smtpCode || $smtpStatus || $detail)
    <div class="small text-muted mt-1">
        @if ($smtpCode)
            <div>{{ __('Code') }}: {{ $smtpCode }}</div>
        @endif
        @if ($smtpStatus)
            <div>{{ $smtpStatus }}</div>
        @endif
        @if ($detail)
            <div>{{ \Illuminate\Support\Str::limit($detail, 90) }}</div>
        @endif
    </div>
@endif