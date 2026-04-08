@extends('sendportal::layouts.app')

@section('title', __('Subscribers'))

@section('heading')
    {{ __('Subscribers & Contact Lists') }}
@endsection

@section('content')

<div class="row mb-4">
    <div class="col-md-12">
        <div class="btn-group" role="group">
            <a href="{{ route('contact-lists.index') }}" class="btn btn-primary">
                <i class="fa fa-list"></i> {{ __('Manage Contact Lists') }}
            </a>
            <a href="{{ route('contact-lists.create') }}" class="btn btn-success">
                <i class="fa fa-plus"></i> {{ __('Create List') }}
            </a>
            <a href="{{ route('sendportal.subscribers.import') }}" class="btn btn-info">
                <i class="fa fa-upload"></i> {{ __('Import Subscribers') }}
            </a>
        </div>
    </div>
</div>

<div class="alert alert-info">
    <strong>{{ __('Contact Lists Overview') }}</strong>
    <p class="mb-0 mt-2">
        {{ __('Your subscribers are organized into Contact Lists. Click a list to open all contacts inside it.') }}
    </p>
</div>

@if ($contactLists->isEmpty())
    <div class="card">
        <div class="card-body text-center">
            <p class="text-muted mb-3">{{ __('No contact lists yet. Create one to get started.') }}</p>
            <div>
                <a href="{{ route('contact-lists.create') }}" class="btn btn-primary mr-2">
                    {{ __('Create Contact List') }}
                </a>
                <a href="{{ route('sendportal.subscribers.import') }}" class="btn btn-success">
                    {{ __('Import Subscribers') }}
                </a>
            </div>
        </div>
    </div>
@else
    <div class="card">
        <div class="card-table table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>{{ __('List Name') }}</th>
                        <th class="text-center">{{ __('Subscribers') }}</th>
                        <th class="text-center">{{ __('Columns') }}</th>
                        <th class="text-center">{{ __('Created') }}</th>
                        <th class="text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($contactLists as $list)
                        @php
                            $columns = $list->columns;

                            if (is_string($columns)) {
                                $decoded = json_decode($columns, true);
                                $columns = is_array($decoded) ? $decoded : [];
                            }

                            $columns = is_array($columns) ? $columns : [];
                        @endphp
                        <tr>
                            <td>
                                    <a href="{{ route('contact-lists.show', $list->id) }}"><strong>{{ $list->name }}</strong></a>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-info">{{ $list->subscribers_count }}</span>
                            </td>
                            <td class="text-center">
                                @if (count($columns) > 0)
                                    <small class="text-muted">{{ count($columns) }} columns</small><br>
                                    <small class="text-muted">{{ implode(', ', $columns) }}</small>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <small class="text-muted">{{ $list->created_at->format('M d, Y') }}</small>
                            </td>
                            <td class="text-right">
                                <a href="{{ route('contact-lists.edit', $list->id) }}" class="btn btn-sm btn-info mr-1" title="{{ __('Edit') }}">
                                    <i class="fa fa-edit"></i>
                                </a>
                                <a href="{{ route('contact-lists.show', $list->id) }}" class="btn btn-sm btn-primary mr-1" title="{{ __('View Contacts') }}">
                                    <i class="fa fa-users"></i>
                                </a>
                                <a href="{{ route('sendportal.subscribers.import', ['preselect_list' => $list->id]) }}" class="btn btn-sm btn-success mr-1" title="{{ __('Import Subscribers') }}">
                                    <i class="fa fa-upload"></i>
                                </a>
                                <form action="{{ route('contact-lists.destroy', $list->id) }}" method="POST" style="display: inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger" title="{{ __('Delete') }}" onclick="return confirm('{{ __('Are you sure? This will delete all subscribers in this list.') }}');">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@endsection
