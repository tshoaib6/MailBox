@extends('sendportal::layouts.app')

@section('title', __('Edit Contact List'))

@section('heading')
    {{ __('Edit Contact List') }}
@endsection

@section('content')

<div class="row">
    <div class="col-md-8 offset-md-2">
        <div class="card">
            <div class="card-body">
                <form action="{{ route('contact-lists.update', $contactList->id) }}" method="POST">
                    @csrf
                    @method('PUT')

                    <div class="form-group">
                        <label for="name">{{ __('List Name') }}</label>
                        <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $contactList->name) }}" required>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="alert alert-info mt-3">
                        <strong>{{ __('Statistics') }}</strong>
                        <ul class="mb-0 mt-2">
                            <li>{{ __('Total Subscribers:') }} <strong>{{ $contactList->subscribers_count }}</strong></li>
                            <li>{{ __('Created:') }} <strong>{{ $contactList->created_at->format('M d, Y H:i') }}</strong></li>
                        </ul>
                    </div>

                    <div class="form-group mt-4">
                        <button type="submit" class="btn btn-primary">{{ __('Update Contact List') }}</button>
                        <a href="{{ route('contact-lists.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
