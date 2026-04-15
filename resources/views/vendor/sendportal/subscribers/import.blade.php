@extends('sendportal::layouts.app')

@push('css')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.13.12/dist/css/bootstrap-select.min.css">
@endpush

@section('heading')
    {{ __('Import Subscribers') }}
@stop

@section('content')

    @if (isset($errors) and count($errors->getBags()))
        <div class="row">
            <div class="col-lg-6 offset-lg-3">
                <div class="alert alert-danger">
                    <ul>
                        @foreach ($errors->getBags() as $key => $bag)
                            @foreach($bag->all() as $error)
                                <li>{{ $key }} - {{ $error }}</li>
                            @endforeach
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif

    @component('sendportal::layouts.partials.card')
        @slot('cardHeader', __('Import Subscribers — CSV or Excel'))

        @slot('cardBody')

            <div class="alert alert-info">
                <strong>Supported file types:</strong> CSV (.csv) and Excel (.xlsx &amp; .xls)
            </div>

            <p><b>Required columns (first row must be the header):</b></p>

            <div class="table-responsive mb-3">
                <table class="table table-bordered table-condensed table-striped">
                    <thead>
                        <tr>
                            <th>id <small class="text-muted">(optional)</small></th>
                            <th>email <small class="text-muted">(required)</small></th>
                            <th>first_name <small class="text-muted">(optional)</small></th>
                            <th>last_name <small class="text-muted">(optional)</small></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td></td>
                            <td>jane@example.com</td>
                            <td>Jane</td>
                            <td>Doe</td>
                        </tr>
                        <tr>
                            <td></td>
                            <td>mark@example.com</td>
                            <td>Mark</td>
                            <td>Smith</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-muted small">
                Leave the <code>id</code> column empty to create new subscribers.
                Fill it to update an existing subscriber by ID.
                Duplicate emails are updated, not duplicated.
            </p>

            <form action="{{ route('sendportal.subscribers.import.store') }}" method="POST" class="form-horizontal" enctype="multipart/form-data">
                @csrf

                {{-- Contact List Selection --}}
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">{{ __('Contact List') }} <span class="text-danger">*</span></label>
                    <div class="col-sm-6">
                        <select name="contact_list_id" id="contact_list_id" class="form-control" required>
                            <option value="">— {{ __('Select or create a contact list') }} —</option>
                            @forelse($contactLists ?? [] as $list)
                                <option value="{{ $list->id }}" {{ request('preselect_list') == $list->id ? 'selected' : '' }}>
                                    {{ $list->name }} ({{ $list->subscribers_count }} subscribers)
                                </option>
                            @empty
                                <option value="" disabled>{{ __('No contact lists found') }}</option>
                            @endforelse
                        </select>
                        <small class="form-text text-muted">
                            <a href="{{ route('contact-lists.create') }}" target="_blank">{{ __('Create a new contact list') }}</a> — opens in new tab
                        </small>
                    </div>
                </div>

                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">File <span class="text-danger">*</span></label>
                    <div class="col-sm-6">
                        <input type="file"
                               name="file"
                               class="form-control-file"
                               accept=".csv,.xls,.xlsx,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                               required>
                        <small class="form-text text-muted">Accepted: .csv, .xls, .xlsx — max 5 MB</small>
                    </div>
                </div>

                <x-sendportal.select-field name="tags[]" :label="__('Tags')" :options="$tags" multiple />

                <div class="form-group row">
                    <div class="offset-sm-3 col-sm-9">
                        <a href="{{ route('sendportal.subscribers.index') }}" class="btn btn-light">{{ __('Back') }}</a>
                        <button type="submit" class="btn btn-primary">{{ __('Upload & Import') }}</button>
                    </div>
                </div>
            </form>

        @endSlot
    @endcomponent

@stop

@push('js')
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.13.12/dist/js/bootstrap-select.min.js"></script>
@endpush
