@extends('sendportal::layouts.app')

@push('css')
<style>
    .main-header,
    .main-content {
        display: block !important;
        visibility: visible !important;
        opacity: 1 !important;
    }

    .contact-list-visible-shell {
        display: block !important;
        visibility: visible !important;
        opacity: 1 !important;
    }
</style>
@endpush

@section('title', __('Contact List Contacts'))

@section('heading')
    {{ __('Contact List: :name', ['name' => $contactList->name]) }}
@endsection

@section('content')

@php
    $columns = $contactList->columns;

    if (is_string($columns)) {
        $decoded = json_decode($columns, true);
        $columns = is_array($decoded) ? $decoded : [];
    }

    $columns = is_array($columns) ? $columns : [];
@endphp

<div class="contact-list-visible-shell">

<div class="row mb-4">
    <div class="col-md-12">
        <div class="btn-group" role="group">
            <a href="{{ route('sendportal.subscribers.index') }}" class="btn btn-light">
                <i class="fa fa-arrow-left"></i> {{ __('Back to Contact Lists') }}
            </a>
            <a href="{{ route('sendportal.subscribers.import', ['preselect_list' => $contactList->id]) }}" class="btn btn-success">
                <i class="fa fa-upload"></i> {{ __('Import More Contacts') }}
            </a>
            <a href="{{ route('sendportal.campaigns.create', ['contact_list_id' => $contactList->id]) }}" class="btn btn-primary">
                <i class="fa fa-paper-plane"></i> {{ __('Create Campaign For This List') }}
            </a>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <strong>{{ __('Total Contacts:') }}</strong> {{ $subscribers->total() }}
        <br>
        <strong>{{ __('Detected Columns:') }}</strong>
        {{ count($columns) > 0 ? implode(', ', $columns) : __('No columns detected yet') }}
    </div>
</div>

<div class="card">
    <div class="card-table table-responsive">
        @php
            $displayColumns = count($columns) > 0 ? $columns : ['email', 'first_name', 'last_name'];
        @endphp
        <table class="table mb-0">
            <thead>
                <tr>
                    @foreach($displayColumns as $column)
                        <th>{{ $column }}</th>
                    @endforeach
                    <th class="text-center">{{ __('Imported At') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($subscribers as $subscriber)
                    <tr>
                        @foreach($displayColumns as $column)
                            @php
                                $value = $subscriber->meta_array[$column] ?? null;

                                if ($value === null) {
                                    $normalized = strtolower(trim($column));

                                    if (in_array($normalized, ['email', 'e-mail', 'email address', 'customer email', 'mail'], true)) {
                                        $value = $subscriber->email;
                                    } elseif (in_array($normalized, ['first_name', 'first name', 'firstname', 'name', 'given name'], true)) {
                                        $value = $subscriber->first_name;
                                    } elseif (in_array($normalized, ['last_name', 'last name', 'lastname', 'surname', 'family name'], true)) {
                                        $value = $subscriber->last_name;
                                    }
                                }

                                if (is_array($value)) {
                                    $value = implode(', ', $value);
                                }

                                $displayValue = ($value === null || $value === '') ? '—' : (string) $value;

                                // Guard against malformed UTF-8 coming from imported CSV/Excel content.
                                if ($displayValue !== '—' && function_exists('iconv')) {
                                    $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $displayValue);
                                    if ($converted !== false) {
                                        $displayValue = $converted;
                                    }
                                }
                            @endphp
                            <td>{{ $displayValue }}</td>
                        @endforeach
                        <td class="text-center">
                            @php
                                $timestamp = is_string($subscriber->created_at) ? strtotime($subscriber->created_at) : false;
                            @endphp
                            {{ $timestamp ? date('M d, Y H:i', $timestamp) : '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($displayColumns) + 1 }}" class="text-center text-muted py-4">{{ __('No contacts found in this list yet.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="card-body">
        {{ $subscribers->links() }}
    </div>
</div>

</div>

@endsection
