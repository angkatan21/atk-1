<div class="stats-form form-inline">
    {foreach from=$fields item=field}
        <div class="form-group min-width_200">
            <div class="control-label"><label>{if $field.label!=""}{$field.label}{/if}</label></div>
            {$field.full}
        </div>
    {/foreach}
</div>

