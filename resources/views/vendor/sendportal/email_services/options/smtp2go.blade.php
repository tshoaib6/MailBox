<x-sendportal.text-field name="settings[api_key]" :label="__('API Key')" :value="Arr::get($settings ?? [], 'api_key')" autocomplete="off" />
<x-sendportal.text-field name="settings[from_email]" :label="__('Default From Email')" :value="Arr::get($settings ?? [], 'from_email')" placeholder="noreply@yourdomain.com" />
