{*
 * sudemi > Support (1.4.0, before on the module page): tickets with sudemi support. Ticket bodies
 * are plain text from sudemi: escaped, never HTML, with line breaks kept by white-space:pre-wrap.
 * $sudemi_output is PrestaShop's own confirmation/error markup for the action just taken.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *}
<div class="sudemi-ui" id="sudemi-support-page">
{include file="./sudemi-header.tpl"}
{$sudemi_output nofilter}
<div class="panel" id="sudemi-support">
  <div class="panel-heading">{$sudemi_support_title|escape:'html':'UTF-8'}</div>
  {if !$sudemi_support}
    <p class="help-block">{$sudemi_support_not_connected|escape:'html':'UTF-8'}</p>
  {else}
    {assign var=sl value=$sudemi_support.labels}
    <p>{$sl.intro|escape:'html':'UTF-8'}</p>
    {if $sudemi_support.error}<div class="alert alert-danger">{$sudemi_support.error|escape:'html':'UTF-8'}</div>{/if}

    {if $sudemi_support.ticket}
      {assign var=t value=$sudemi_support.ticket}
      <h4 id="sudemi-ticket">{$t.number|escape:'html':'UTF-8'} - {$t.subject|escape:'html':'UTF-8'}</h4>
      <p><span class="label label-default">{$t.category|escape:'html':'UTF-8'}</span>
        <span class="label {if $t.closed}label-default{else}label-info{/if}">{$t.status|escape:'html':'UTF-8'}</span>
        <a href="{$sudemi_support.list_url|escape:'html':'UTF-8'}" style="margin-left:1em">{$sl.back|escape:'html':'UTF-8'}</a></p>
      {foreach from=$t.messages item=m}
        <div class="sudemi-message" style="border-left:4px solid {if $m.support}#25b9d7{else}#bbcdd2{/if};background:{if $m.support}#eefafc{else}#f8f8f8{/if};padding:.5em 1em;margin:0 0 .75em {if $m.support}0{else}2em{/if}">
          <p style="margin:0 0 .25em"><strong>{if $m.support}{$sl.support|escape:'html':'UTF-8'}{else}{$sl.you|escape:'html':'UTF-8'}{/if}</strong>
            <small class="text-muted">{$m.at|escape:'html':'UTF-8'}</small></p>
          <div style="white-space:pre-wrap;word-wrap:break-word">{$m.body|escape:'html':'UTF-8'}</div>
        </div>
      {/foreach}
      {if $t.closed}<p class="help-block">{$sl.closed|escape:'html':'UTF-8'}</p>{/if}
      <form method="post" action="{$sudemi_support.list_url|escape:'html':'UTF-8'}">
        <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
        <input type="hidden" name="sudemi_ticket" value="{$t.id|escape:'html':'UTF-8'}" />
        <div class="form-group">
          <label for="sudemi-support-reply">{$sl.reply|escape:'html':'UTF-8'}</label>
          <textarea id="sudemi-support-reply" name="sudemi_support_reply_message" rows="5" maxlength="10000" required class="form-control">{$sudemi_support.form.reply|escape:'html':'UTF-8'}</textarea>
        </div>
        <button type="submit" name="sudemi_support_reply" class="btn btn-primary">{$sl.send_reply|escape:'html':'UTF-8'}</button>
      </form>
      <hr />
    {/if}

    <h4>{$sl.tickets|escape:'html':'UTF-8'}</h4>
    {if $sudemi_support.tickets === null}
      <div class="alert alert-warning">{$sl.list_error|escape:'html':'UTF-8'}</div>
    {elseif $sudemi_support.tickets}
      <table class="table" id="sudemi-support-tickets">
        <thead><tr>
          <th>{$sl.number|escape:'html':'UTF-8'}</th>
          <th>{$sl.subject|escape:'html':'UTF-8'}</th>
          <th>{$sl.status|escape:'html':'UTF-8'}</th>
          <th>{$sl.updated|escape:'html':'UTF-8'}</th>
          <th></th>
        </tr></thead>
        <tbody>
        {foreach from=$sudemi_support.tickets item=row}
          <tr>
            <td><code>{$row.number|escape:'html':'UTF-8'}</code></td>
            <td>{$row.subject|escape:'html':'UTF-8'}<br><small class="text-muted">{$row.category|escape:'html':'UTF-8'}</small></td>
            <td>{$row.status|escape:'html':'UTF-8'}</td>
            <td>{$row.updated|escape:'html':'UTF-8'}</td>
            <td><a class="btn btn-default btn-sm" href="{$row.url|escape:'html':'UTF-8'}">{$sl.view|escape:'html':'UTF-8'}</a></td>
          </tr>
        {/foreach}
        </tbody>
      </table>
    {else}
      <p class="help-block">{$sl.empty|escape:'html':'UTF-8'}</p>
    {/if}

    <h4>{$sl.new|escape:'html':'UTF-8'}</h4>
    <form method="post" action="{$sudemi_support.list_url|escape:'html':'UTF-8'}">
      <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
      <div class="form-group">
        <label for="sudemi-support-category">{$sl.category|escape:'html':'UTF-8'}</label>
        <select id="sudemi-support-category" name="sudemi_support_category" required class="form-control">
          <option value="">{$sl.choose|escape:'html':'UTF-8'}</option>
          {foreach from=$sudemi_support.categories key=value item=label}
            <option value="{$value|escape:'html':'UTF-8'}" {if $sudemi_support.form.category == $value}selected{/if}>{$label|escape:'html':'UTF-8'}</option>
          {/foreach}
        </select>
      </div>
      <div class="form-group">
        <label for="sudemi-support-subject">{$sl.subject|escape:'html':'UTF-8'}</label>
        <input type="text" id="sudemi-support-subject" name="sudemi_support_subject" minlength="3" maxlength="200" required class="form-control" value="{$sudemi_support.form.subject|escape:'html':'UTF-8'}" />
      </div>
      <div class="form-group">
        <label for="sudemi-support-message">{$sl.message|escape:'html':'UTF-8'}</label>
        <textarea id="sudemi-support-message" name="sudemi_support_message" rows="6" maxlength="10000" required class="form-control">{$sudemi_support.form.message|escape:'html':'UTF-8'}</textarea>
      </div>
      <div class="form-group">
        <label for="sudemi-support-order">{$sl.order|escape:'html':'UTF-8'}</label>
        <input type="text" id="sudemi-support-order" name="sudemi_support_order" maxlength="36" class="form-control" style="max-width:24em" value="{$sudemi_support.form.order|escape:'html':'UTF-8'}" />
        <p class="help-block">{$sl.order_help|escape:'html':'UTF-8'}</p>
      </div>
      <button type="submit" name="sudemi_support_create" class="btn btn-primary">{$sl.send|escape:'html':'UTF-8'}</button>
    </form>
  {/if}
</div>
</div>
