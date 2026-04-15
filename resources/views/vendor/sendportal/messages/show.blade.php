@extends('sendportal::layouts.app')

@section('title', __('Message'))

@section('heading', __('Message Details'))

@section('content')

    <script>
        function resizeTextArea(element) {
            newHeight = element.contentWindow.document.body.scrollHeight;
            element.height = (newHeight + 10) + "px";
        }

    </script>

    <div class="card mb-4">
        <div class="card-header card-header-accent">
            <div class="card-header-inner">
                <div class="float-right">
                    @if ($message->sent_at)
                        {{ __('Sent') }} <span title="{{ $message->sent_at }}">{{ $message->sent_at->diffForHumans() }}</span>
                    @else
                        <form action="{{ route('sendportal.messages.send') }}" method="post">
                            @csrf
                            <input type="hidden" name="id" value="{{ $message->id }}">
                            <button type="submit" class="btn btn-sm btn-primary">{{ __('Send now') }}</button>
                        </form>
                    @endif
                </div>
                <div class="clearfix"></div>
            </div>
        </div>
        <div class="card-body">
            <table class="table table-sm mb-0">
                <tbody>
                <tr>
                    <td width="150px"><b>{{ __('To') }}:</b></td>
                    <td>{{ $message->recipient_email }}</td>
                </tr>
                <tr>
                    <td><b>{{ __('Subject') }}:</b></td>
                    <td>{{ $subject }}</td>
                </tr>
                <tr>
                    <td><b>{{ __('From') }}:</b></td>
                    <td>{{ $message->from_name }} &lt;{{ $message->from_email }}&gt;</td>
                </tr>
                <tr>
                    <td><b>{{ __('Result') }}:</b></td>
                    <td>@include('sendportal::messages.partials.status-row')</td>
                </tr>
                <tr>
                    <td><b>{{ __('Attempted At') }}:</b></td>
                    <td>{{ optional($message->attempted_at)->format('M d, Y H:i:s') ?? '—' }}</td>
                </tr>
                <tr>
                    <td><b>{{ __('SMTP Code') }}:</b></td>
                    <td>{{ $message->smtp_code ?? '—' }}</td>
                </tr>
                <tr>
                    <td><b>{{ __('SMTP Status') }}:</b></td>
                    <td>{{ $message->smtp_status ?? '—' }}</td>
                </tr>
                <tr>
                    <td><b>{{ __('SMTP Message') }}:</b></td>
                    <td>{{ $message->smtp_message ?? '—' }}</td>
                </tr>
                <tr>
                    <td><b>{{ __('Error Detail') }}:</b></td>
                    <td>{{ $message->error_detail ?? '—' }}</td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-5">
        <div class="card-header">{{ __('Rendered Email') }}</div>
        <div class="card-body">
            <iframe width="100%" height="100%" scrolling="no" frameborder="0" srcdoc="{{ $content }}" onload="resizeTextArea(this)"></iframe>
        </div>
    </div>

@endsection