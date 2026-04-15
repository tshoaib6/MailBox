<div class="form-group row form-group-name">
    <label for="id-field-name" class="control-label col-sm-2">{{ __('Template Name') }}</label>
    <div class="col-sm-6">
        <input id="id-field-name" class="form-control" name="name" type="text" value="{{ old('name', $template->name ?? '') }}">
    </div>
</div>

<div class="form-group row">
    <label class="control-label col-sm-2">{{ __('Contact Variables') }}</label>
    <div class="col-sm-10">
        <div class="alert alert-info mb-2">
            {{ __('Use these tags to pull values from your imported subscribers at send time.') }}
        </div>
        <div class="d-flex flex-wrap" style="gap: 8px;">
            <button type="button" class="btn btn-sm btn-light js-insert-tag" data-tag="@{{first_name}}">@{{first_name}}</button>
            <button type="button" class="btn btn-sm btn-light js-insert-tag" data-tag="@{{last_name}}">@{{last_name}}</button>
            <button type="button" class="btn btn-sm btn-light js-insert-tag" data-tag="@{{email}}">@{{email}}</button>
            <button type="button" class="btn btn-sm btn-light js-insert-tag" data-tag="@{{unsubscribe_url}}">@{{unsubscribe_url}}</button>
            <button type="button" class="btn btn-sm btn-light js-insert-tag" data-tag="@{{webview_url}}">@{{webview_url}}</button>
        </div>
        <small class="form-text text-muted mt-2">
            CSV columns first_name, last_name, and email map to @{{first_name}}, @{{last_name}}, and @{{email}}.
        </small>
    </div>
</div>

@include('sendportal::templates.partials.editor')

<div class="form-group row">
    <div class="offset-2 col-10">
        <a href="#" class="btn btn-md btn-secondary btn-preview">{{ __('Show Preview') }}</a>
        <button class="btn btn-primary btn-md" type="submit">{{ __('Save Template') }}</button>
    </div>
</div>

@push('js')
    <script>
        $(document).on('click', '.js-insert-tag', function () {
            const tag = $(this).data('tag');

            if (window.sendportalTemplateEditor) {
                const doc = window.sendportalTemplateEditor.getDoc();
                doc.replaceSelection(tag);
                window.sendportalTemplateEditor.focus();
                return;
            }

            const textarea = document.getElementById('id-field-content');
            if (!textarea) {
                return;
            }

            const start = textarea.selectionStart || textarea.value.length;
            const end = textarea.selectionEnd || textarea.value.length;
            const before = textarea.value.substring(0, start);
            const after = textarea.value.substring(end);
            textarea.value = before + tag + after;
            textarea.focus();
            textarea.selectionStart = textarea.selectionEnd = start + tag.length;
        });
    </script>
@endpush
