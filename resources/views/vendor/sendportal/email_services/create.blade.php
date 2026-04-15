@extends('sendportal::layouts.app')

@section('heading')
    {{ __('Add Email Service') }}
@stop

@section('content')

    @component('sendportal::layouts.partials.card')
        @slot('cardHeader', __('Create Email Service'))

        @slot('cardBody')
            <form action="{{ route('sendportal.email_services.store') }}" method="POST" class="form-horizontal">
                @csrf
                <x-sendportal.text-field name="name" :label="__('Name')" />
                <x-sendportal.select-field name="type_id" :label="__('Email Service')" :options="$emailServiceTypes" />

                {{-- All provider field sections, shown/hidden by JS --}}

                {{-- SES (1) --}}
                <div class="sp-provider-fields" data-type="1" style="display:none;">
                    <x-sendportal.text-field name="settings[key]" label="AWS Access Key" autocomplete="off" />
                    <x-sendportal.text-field name="settings[secret]" label="AWS Secret Access Key" autocomplete="off" />
                    <x-sendportal.text-field name="settings[region]" label="AWS Region" />
                    <x-sendportal.text-field name="settings[configuration_set_name]" label="Configuration Set Name" />
                </div>

                {{-- SendGrid (2) --}}
                <div class="sp-provider-fields" data-type="2" style="display:none;">
                    <x-sendportal.text-field name="settings[key]" label="API Key" autocomplete="off" />
                </div>

                {{-- Mailgun (3) --}}
                <div class="sp-provider-fields" data-type="3" style="display:none;">
                    <x-sendportal.text-field name="settings[key]" label="API Key" autocomplete="off" />
                    <x-sendportal.text-field name="settings[webhook_key]" label="Webhook Key" autocomplete="off" />
                    <x-sendportal.text-field name="settings[domain]" label="Domain" />
                    <x-sendportal.select-field name="settings[zone]" label="Zone" :options="['EU' => 'EU', 'US' => 'US']" />
                </div>

                {{-- Postmark (4) --}}
                <div class="sp-provider-fields" data-type="4" style="display:none;">
                    <x-sendportal.text-field name="settings[key]" label="API Key" autocomplete="off" />
                    <x-sendportal.text-field name="settings[message_stream]" label="Message Stream" autocomplete="off" />
                </div>

                {{-- Mailjet (5) --}}
                <div class="sp-provider-fields" data-type="5" style="display:none;">
                    <x-sendportal.text-field name="settings[key]" label="Mailjet Key" autocomplete="off" />
                    <x-sendportal.text-field name="settings[secret]" label="Mailjet Secret" autocomplete="off" />
                    <x-sendportal.select-field name="settings[zone]" label="Zone" :options="['Default' => 'Default', 'US' => 'US']" />
                </div>

                {{-- SMTP (6) --}}
                <div class="sp-provider-fields" data-type="6" style="display:none;">
                    <x-sendportal.text-field name="settings[host]" label="SMTP Host" />
                    <x-sendportal.text-field type="number" name="settings[port]" label="SMTP Port" />
                    <x-sendportal.text-field name="settings[encryption]" label="Encryption (e.g. tls or ssl)" />
                    <x-sendportal.text-field name="settings[username]" label="Username" />
                    <x-sendportal.text-field type="password" name="settings[password]" label="Password" />
                </div>

                {{-- Postal (7) --}}
                <div class="sp-provider-fields" data-type="7" style="display:none;">
                    <x-sendportal.text-field name="settings[postal_host]" label="Postal Host" />
                    <x-sendportal.text-field name="settings[key]" label="API Key" autocomplete="off" />
                </div>

                {{-- Smtp2go (8) --}}
                <div class="sp-provider-fields" data-type="8" style="display:none;">
                    <x-sendportal.text-field name="settings[api_key]" label="Smtp2go API Key" autocomplete="off" />
                    <x-sendportal.text-field name="settings[from_email]" label="Default From Email" placeholder="noreply@yourdomain.com" />
                </div>

                <x-sendportal.submit-button :label="__('Save')" />
            </form>
        @endSlot
    @endcomponent

@stop

@push('js')
<script>
    (function () {
        var select = document.querySelector('select[name="type_id"]');

        function showFields(typeId) {
            document.querySelectorAll('.sp-provider-fields').forEach(function (el) {
                el.style.display = (el.getAttribute('data-type') === String(typeId)) ? '' : 'none';
            });
        }

        if (select) {
            showFields(select.value);
            select.addEventListener('change', function () {
                showFields(this.value);
            });
        }
    })();
</script>
@endpush
