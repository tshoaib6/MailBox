@extends('sendportal::layouts.app')

@section('title', __('Contact Lists'))

@section('heading')
    {{ __('Contact Lists') }}
@endsection

@section('content')

<div class="row mb-4">
    <div class="col-md-12">
        <a href="{{ route('contact-lists.create') }}" class="btn btn-primary">
            <i class="fa fa-plus"></i> {{ __('Create Contact List') }}
        </a>
    </div>
</div>

@if ($contactLists->isEmpty())
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body text-center">
                    <p class="text-muted mb-3">{{ __('No contact lists yet.') }}</p>
                    <a href="{{ route('contact-lists.create') }}" class="btn btn-sm btn-primary">
                        {{ __('Create your first contact list') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
@else
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('Name') }}</th>
                                <th class="text-center">{{ __('Subscribers') }}</th>
                                <th class="text-center">{{ __('Created') }}</th>
                                <th class="text-right" style="width: 150px;">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($contactLists as $list)
                                <tr>
                                    <td>
                                        <a href="{{ route('contact-lists.show', $list->id) }}"><strong>{{ $list->name }}</strong></a>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-info">{{ $list->subscribers_count }}</span>
                                    </td>
                                    <td class="text-center">
                                        <small class="text-muted">{{ $list->created_at->format('M d, Y') }}</small>
                                    </td>
                                    <td class="text-right">
                                        <a href="{{ route('contact-lists.show', $list->id) }}" class="btn btn-sm btn-primary" title="{{ __('View Contacts') }}">
                                            <i class="fa fa-users"></i>
                                        </a>
                                        <a href="{{ route('contact-lists.edit', $list->id) }}" class="btn btn-sm btn-info" title="{{ __('Edit') }}">
                                            <i class="fa fa-edit"></i>
                                        </a>
                                        <a href="{{ route('sendportal.subscribers.import', ['preselect_list' => $list->id]) }}" class="btn btn-sm btn-success" title="{{ __('Import Subscribers') }}">
                                            <i class="fa fa-upload"></i>
                                        </a>
                                        <form action="{{ route('contact-lists.destroy', $list->id) }}" method="POST" style="display: inline;">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger" title="{{ __('Delete') }}" onclick="return confirm('{{ __('Are you sure?') }}');">
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
        </div>
    </div>
@endif

@endsection
