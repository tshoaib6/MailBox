<x-sendportal.text-field name="name" :label="__('Campaign Name')" :value="$campaign->name ?? old('name')" />

{{-- Contact List Selection --}}
<div class="form-group row">
    <label for="id-field-contact-list" class="col-sm-3 col-form-label">{{ __('Contact List') }}</label>
    <div class="col-sm-9">
        <select name="contact_list_id" id="id-field-contact-list" class="form-control">
            <option value="">— {{ __('Select a contact list') }} —</option>
            @forelse($contactLists ?? [] as $list)
                <option value="{{ $list->id }}" {{ ($campaign->contact_list_id ?? old('contact_list_id') ?? request('contact_list_id')) == $list->id ? 'selected' : '' }}>
                    {{ $list->name }}
                </option>
            @empty
                <option value="" disabled>{{ __('No contact lists available. Import contacts first.') }}</option>
            @endforelse
        </select>
        <small class="form-text text-muted">{{ __('Each contact list can have custom column headers. The mapping shows which columns are used for personalization variables.') }}</small>
    </div>
</div>

{{-- Column Mapping Display --}}
<div id="sp-mapping-panel" class="form-group row d-none">
    <label class="col-sm-3 col-form-label">{{ __('Column Mapping') }}</label>
    <div class="col-sm-9">
        <div class="card" id="sp-mapping-content">
            <div class="card-body">
                <p class="text-muted">{{ __('Select a contact list to see column mappings') }}</p>
            </div>
        </div>
    </div>
</div>

{{-- Subject with variable buttons --}}
<div class="form-group row">
    <label for="id-field-subject" class="col-sm-3 col-form-label">{{ __('Email Subject') }}</label>
    <div class="col-sm-9">
        <input type="text" name="subject" id="id-field-subject" class="form-control" value="{{ $campaign->subject ?? old('subject') }}">
        <div class="mt-1">
            <small class="text-muted mr-1">{{ __('Insert:') }}</small>
            @foreach(['first_name','last_name','email'] as $var)
                <button type="button" class="btn btn-sm btn-outline-secondary mr-1 sp-subject-var" data-var="@{{{{ $var }}}}">@{{{{ $var }}}}</button>
            @endforeach
        </div>
    </div>
</div>

<x-sendportal.text-field name="from_name" :label="__('From Name')" :value="$campaign->from_name ?? old('from_name')" />
<x-sendportal.text-field name="from_email" :label="__('From Email')" type="email" :value="$campaign->from_email ?? old('from_email')" />

<x-sendportal.select-field name="template_id" :label="__('Template')" :options="$templates" :value="$campaign->template_id ?? old('template_id')" />

<x-sendportal.select-field name="email_service_id" :label="__('Email Service')" :options="$emailServices->pluck('formatted_name', 'id')" :value="$campaign->email_service_id ?? old('email_service_id')" />

<x-sendportal.checkbox-field name="is_open_tracking" :label="__('Track Opens')" value="1" :checked="$campaign->is_open_tracking ?? true" />
<x-sendportal.checkbox-field name="is_click_tracking" :label="__('Track Clicks')" value="1" :checked="$campaign->is_click_tracking ?? true" />

{{-- Content with variable picker + Summernote --}}
<div class="form-group row">
    <label class="col-sm-3 col-form-label">{{ __('Content') }}</label>
    <div class="col-sm-9">
        <div class="mb-2">
            <small class="text-muted mr-1">{{ __('Insert variable:') }}</small>
            @foreach(['first_name','last_name','email','unsubscribe_url','webview_url'] as $var)
                <button type="button" class="btn btn-sm btn-outline-secondary mr-1 sp-content-var" data-var="@{{{{ $var }}}}">@{{{{ $var }}}}</button>
            @endforeach
        </div>
        <textarea name="content" id="id-field-content" class="form-control">{{ $campaign->content ?? old('content') }}</textarea>
    </div>
</div>

<div class="form-group row">
    <div class="offset-sm-3 col-sm-9">
        <a href="{{ route('sendportal.campaigns.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
        <button type="submit" class="btn btn-primary">{{ __('Save and continue') }}</button>
    </div>
</div>

@include('sendportal::layouts.partials.summernote')

@push('js')
    <script>
        $(function () {
            const smtp = {{
                $emailServices->filter(function ($service) {
                    return $service->type_id === \Sendportal\Base\Models\EmailServiceType::SMTP;
                })
                ->pluck('id')
            }};

            let service_id = $('select[name="email_service_id"]').val();
            toggleTracking(smtp.includes(parseInt(service_id, 10)));

            $('select[name="email_service_id"]').on('change', function () {
                toggleTracking(smtp.includes(parseInt(this.value, 10)));
            });

            // Insert variable into subject input at cursor position
            $('.sp-subject-var').on('click', function () {
                var $input = $('#id-field-subject');
                var val = $input.val();
                var pos = $input[0].selectionStart || val.length;
                $input.val(val.slice(0, pos) + $(this).data('var') + val.slice(pos));
                $input[0].focus();
                $input[0].setSelectionRange(pos + $(this).data('var').length, pos + $(this).data('var').length);
            });

            // Insert variable into Summernote editor at cursor
            $('.sp-content-var').on('click', function () {
                var tag = $(this).data('var');
                $('#id-field-content').summernote('focus');
                $('#id-field-content').summernote('insertText', tag);
            });

            // Handle contact list selection and show mappings
            $('#id-field-contact-list').on('change', function () {
                var contactListId = $(this).val();
                if (contactListId) {
                    loadMappings(contactListId);
                } else {
                    $('#sp-mapping-panel').addClass('d-none');
                }
            });

            // Load mappings on page load if a contact list is already selected
            var selectedId = $('#id-field-contact-list').val();
            if (selectedId) {
                loadMappings(selectedId);
            }
        });

        function loadMappings(contactListId) {
            $.get('{{ route('api.contact-list-mappings', ['id' => '__ID__']) }}'.replace('__ID__', contactListId),
                function (data) {
                    var html = '<table class="table table-sm mb-0">' +
                        '<thead><tr><th>' + "{{ __('CSV Column') }}" + '</th><th>' + "{{ __('Merge Variable') }}" + '</th></tr></thead><tbody>';

                    $.each(data.mappings || [], function (i, mapping) {
                        html += '<tr><td><code>' + mapping.csv_column + '</code></td><td><code>@{{' + mapping.merge_variable + '}}</code></td></tr>';
                    });

                    html += '</tbody></table>';
                    $('#sp-mapping-content').html(html);
                    $('#sp-mapping-panel').removeClass('d-none');
                }
            );
        }

        function toggleTracking(disable) {
            let $open = $('input[name="is_open_tracking"]');
            let $click = $('input[name="is_click_tracking"]');

            if (disable) {
                $open.attr('disabled', 'disabled');
                $click.attr('disabled', 'disabled');
            } else {
                $open.removeAttr('disabled');
                $click.removeAttr('disabled');
            }
        }
    </script>
@endpush
