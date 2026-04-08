@extends('sendportal::layouts.app')

@section('title', __('Contact List Contacts'))

@section('heading')
    {{ __('Contact List: :name', ['name' => $contactList->name]) }}
@endsection

@section('content')

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
        {{ !empty($contactList->columns) ? implode(', ', $contactList->columns) : __('No columns detected yet') }}
    </div>
</div>

<div class="card">
    <div class="card-table table-responsive">
        @php
            $displayColumns = !empty($contactList->columns) ? $contactList->columns : ['email', 'first_name', 'last_name'];
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
                            @endphp
                            <td>{{ ($value === null || $value === '') ? '—' : $value }}</td>
                        @endforeach
                        <td class="text-center">{{ \Illuminate\Support\Carbon::parse($subscriber->created_at)->format('M d, Y H:i') }}</td>
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

@endsection
