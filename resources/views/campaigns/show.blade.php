@extends('sendportal::layouts.app')

@section('title', __('Campaign Details'))

@section('heading')
    {{ __('Campaign Details') }}: {{ $campaign->name }}
@endsection

@section('content')

<div class="row mb-4">
    <div class="col-md-12">
        <div class="btn-group" role="group">
            <a href="{{ route('sendportal.campaigns.index') }}" class="btn btn-light">
                <i class="fa fa-arrow-left"></i> {{ __('Back to Campaigns') }}
            </a>
            @if($campaign->draft)
                <a href="{{ route('sendportal.campaigns.preview', $campaign->id) }}" class="btn btn-primary">
                    <i class="fa fa-paper-plane"></i> {{ __('Open Preview') }}
                </a>
            @endif
            @if(! $campaign->sent)
                <form action="{{ route('sendportal.campaigns.dispatch-now', $campaign->id) }}" method="POST" style="display:inline;">
                    @csrf
                    <button type="submit" class="btn btn-success">
                        <i class="fa fa-bolt"></i> {{ __('Dispatch Now') }}
                    </button>
                </form>
            @endif
            <a href="{{ route('sendportal.campaigns.reports.recipients', $campaign->id) }}" class="btn btn-info">
                <i class="fa fa-users"></i> {{ __('Recipient Report') }}
            </a>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-2">
        <div class="card"><div class="card-body"><strong>{{ __('Status') }}:</strong><br>{{ optional($campaign->status)->name ?? '—' }}</div></div>
    </div>
    <div class="col-md-2">
        <div class="card"><div class="card-body"><strong>{{ __('Email Service') }}:</strong><br>{{ optional($campaign->email_service)->name ?? '—' }}</div></div>
    </div>
    <div class="col-md-2">
        <div class="card"><div class="card-body"><strong>{{ __('Provider') }}:</strong><br>{{ optional(optional($campaign->email_service)->type)->name ?? '—' }}</div></div>
    </div>
    <div class="col-md-2">
        <div class="card"><div class="card-body"><strong>{{ __('Total Recipients') }}:</strong><br>{{ $recipientStats['total'] }}</div></div>
    </div>
    <div class="col-md-2">
        <div class="card"><div class="card-body"><strong>{{ __('Sent') }}:</strong><br>{{ $recipientStats['sent'] }}</div></div>
    </div>
    <div class="col-md-2">
        <div class="card"><div class="card-body"><strong>{{ __('Delivered') }}:</strong><br>{{ $recipientStats['delivered'] }}</div></div>
    </div>
    <div class="col-md-2">
        <div class="card"><div class="card-body"><strong>{{ __('Opened') }}:</strong><br>{{ $recipientStats['opened'] }}</div></div>
    </div>
    <div class="col-md-2">
        <div class="card"><div class="card-body"><strong>{{ __('Clicked') }}:</strong><br>{{ $recipientStats['clicked'] }}</div></div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-2 offset-md-10">
        <div class="card"><div class="card-body text-danger"><strong>{{ __('Failed') }}:</strong><br>{{ $recipientStats['failed'] }}</div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">{{ __('Recipient Status Details') }}</div>
    <div class="card-table table-responsive">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th>{{ __('Email') }}</th>
                    <th>{{ __('Email Service') }}</th>
                    <th>{{ __('Queued At') }}</th>
                    <th>{{ __('Sent At') }}</th>
                    <th>{{ __('Delivered At') }}</th>
                    <th>{{ __('Opened At') }}</th>
                    <th>{{ __('Opens') }}</th>
                    <th>{{ __('Clicked At') }}</th>
                    <th>{{ __('Clicks') }}</th>
                    <th>{{ __('Bounced') }}</th>
                    <th>{{ __('Complained') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($messages as $message)
                    <tr>
                        <td>{{ $message->recipient_email }}</td>
                        <td>
                            {{ optional($campaign->email_service)->name ?? '—' }}
                            @if(optional(optional($campaign->email_service)->type)->name)
                                <div class="text-muted small">{{ optional(optional($campaign->email_service)->type)->name }}</div>
                            @endif
                        </td>
                        <td>{{ $message->queued_at ? $message->queued_at->format('M d, Y H:i:s') : '—' }}</td>
                        <td>{{ $message->sent_at ? $message->sent_at->format('M d, Y H:i:s') : 'Not sent' }}</td>
                        <td>{{ $message->delivered_at ? $message->delivered_at->format('M d, Y H:i:s') : '—' }}</td>
                        <td>{{ $message->opened_at ? $message->opened_at->format('M d, Y H:i:s') : '—' }}</td>
                        <td>{{ $message->open_count ?? 0 }}</td>
                        <td>{{ $message->clicked_at ? $message->clicked_at->format('M d, Y H:i:s') : '—' }}</td>
                        <td>{{ $message->click_count ?? 0 }}</td>
                        <td>{{ $message->bounced_at ? $message->bounced_at->format('M d, Y H:i:s') : 'No' }}</td>
                        <td>{{ $message->complained_at ? $message->complained_at->format('M d, Y H:i:s') : 'No' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="text-center text-muted py-4">{{ __('No recipients yet. Send the campaign to generate messages.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-body">
        {{ $messages->links() }}
    </div>
</div>

@endsection
