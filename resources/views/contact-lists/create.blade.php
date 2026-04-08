@extends('sendportal::layouts.app')

@section('title', __('Create Contact List'))

@section('heading')
    {{ __('Create Contact List') }}
@endsection

@section('content')

<div class="row">
    <div class="col-md-8 offset-md-2">
        <div class="card">
            <div class="card-body">
                <form action="{{ route('contact-lists.store') }}" method="POST">
                    @csrf

                    <div class="form-group">
                        <label for="name">{{ __('List Name') }}</label>
                        <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" placeholder="{{ __('e.g., Newsletter Subscribers, VIP Clients') }}" value="{{ old('name') }}" required>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <small class="form-text text-muted">{{ __('Give your contact list a descriptive name.') }}</small>
                    </div>

                    <div class="form-group mt-4">
                        <button type="submit" class="btn btn-primary">{{ __('Create Contact List') }}</button>
                        <a href="{{ route('contact-lists.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
