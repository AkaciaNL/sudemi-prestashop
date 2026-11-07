{*
 * The configuration screen. Everything printed here is escaped by Smarty's `escape` modifier;
 * nothing is echoed raw, because a module screen is a privileged page.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *}
<div class="panel">
  <div class="panel-heading"><i class="icon-shopping-cart"></i> sudemi</div>

  {if !$sudemi_supported}
    <div class="alert alert-danger">
      {l s='This server is missing the sodium extension. The connection needs it to sign requests and will not work without it. Ask your host to enable it.' mod='sudemi'}
    </div>
  {/if}

  {if $sudemi_connected}
    <div class="alert alert-success">{l s='This shop is connected to sudemi.' mod='sudemi'}</div>

    <table class="table">
      <tr>
        <th style="width:14rem">{l s='Shop address' mod='sudemi'}</th>
        <td>
          <code>{$sudemi_shop_url|escape:'html':'UTF-8'}</code>
          <p class="help-block">{l s='Fixed. sudemi checked that this shop answered from this address, and a listing tells a buyer where it came from - so changing it means connecting again from the new address.' mod='sudemi'}</p>
        </td>
      </tr>
      <tr>
        <th>{l s='Install' mod='sudemi'}</th>
        <td>
          <code>{$sudemi_install_id|escape:'html':'UTF-8'}</code>
          <p class="help-block">{l s='Revoke it on sudemi to cut this shop off immediately.' mod='sudemi'}</p>
        </td>
      </tr>
      <tr>
        <th>{l s='Signing key' mod='sudemi'}</th>
        <td>
          <code>{l s='held on this server' mod='sudemi'}</code>
          <p class="help-block">{l s='The private half never leaves this server and is never sent to sudemi. It cannot be shown here either.' mod='sudemi'}</p>
        </td>
      </tr>
    </table>

    {* Whether sudemi is showing this shop's products, in sudemi's own words.
       Worth its own panel because the failure it reports is invisible from here: products stop
       being listed when the beats stop arriving, and from this side everything looks normal
       module active, catalogue intact, settings right. The cause is usually no cron entry on
       the host, and without this the first sign of it is a seller wondering where the orders
       went. *}
    <h4>{l s='Listing status' mod='sudemi'}</h4>

    {if !$sudemi_status}
      <div class="alert alert-warning">
        {l s='This shop has not reported in to sudemi yet. Add the cron entry below; until it runs, beats are only sent while someone is using the back office.' mod='sudemi'}
      </div>
    {elseif !$sudemi_status.reachable}
      <div class="alert alert-danger">
        {l s='This shop could not reach sudemi. Its products stay listed for a short while and are then paused, because sudemi will not quote a price it cannot confirm.' mod='sudemi'}
      </div>
    {elseif !$sudemi_status.connected}
      <div class="alert alert-danger">{$sudemi_status.message|escape:'html':'UTF-8'}</div>
    {elseif !$sudemi_status.listed}
      <div class="alert alert-warning">{$sudemi_status.reason|escape:'html':'UTF-8'}</div>
    {else}
      <div class="alert alert-success">{l s='Products are being shown on sudemi.' mod='sudemi'}</div>
    {/if}

    <table class="table">
      <tr>
        <th style="width:14rem">{l s='Price changes waiting' mod='sudemi'}</th>
        <td>
          <code>{$sudemi_pending|intval}</code>
          <p class="help-block">{l s='Price or stock changes that could not be sent yet. They are retried on the next report and are not lost.' mod='sudemi'}</p>
        </td>
      </tr>
      {if $sudemi_status.trusted_until}
      <tr>
        <th>{l s='Prices confirmed until' mod='sudemi'}</th>
        <td>
          <code>{$sudemi_status.trusted_until|escape:'html':'UTF-8'}</code>
          <p class="help-block">{l s='After this, sudemi stops showing this shop until it reports in again. Nothing is deleted.' mod='sudemi'}</p>
        </td>
      </tr>
      {/if}
      <tr>
        <th>{l s='Cron entry' mod='sudemi'}</th>
        <td>
          <code>*/5 * * * * curl -fsS "{$sudemi_cron_url|escape:'html':'UTF-8'}" &gt;/dev/null</code>
          <p class="help-block">{l s='Add this to the crontab of the account that runs this shop. It tells sudemi every five minutes that this shop is still here and that its prices still stand, and it sends any price change that did not get through the first time. The link contains a secret; treat it like a password.' mod='sudemi'}</p>
        </td>
      </tr>
    </table>

    {if $sudemi_history === null}
      <div class="alert alert-warning">{$sudemi_history_error|escape:'html':'UTF-8'}</div>
    {else}
      <h4>{$sudemi_history.labels.title|escape:'html':'UTF-8'}</h4>
      {if $sudemi_history.awaiting}<div class="alert alert-warning">{$sudemi_history.awaiting|escape:'html':'UTF-8'}</div>{/if}
      {if $sudemi_history.rows}
        <table class="table" id="sudemi-order-history">
          <thead><tr>
            <th>{$sudemi_history.labels.order|escape:'html':'UTF-8'}</th>
            <th>{$sudemi_history.labels.items|escape:'html':'UTF-8'}</th>
            <th>{$sudemi_history.labels.status|escape:'html':'UTF-8'}</th>
            <th>{$sudemi_history.labels.net|escape:'html':'UTF-8'}</th>
            <th>{$sudemi_history.labels.customer|escape:'html':'UTF-8'}</th>
          </tr></thead>
          <tbody>
          {foreach from=$sudemi_history.rows item=row}
            <tr>
              <td><code>{$row.id|escape:'html':'UTF-8'}</code><br><small>{$row.date|escape:'html':'UTF-8'}</small></td>
              <td>{$row.items|escape:'html':'UTF-8'}</td>
              <td>{$row.status|escape:'html':'UTF-8'}</td>
              <td>{$row.net|escape:'html':'UTF-8'}</td>
              <td>{$row.customer|escape:'html':'UTF-8'}</td>
            </tr>
          {/foreach}
          </tbody>
        </table>
      {else}
        <p class="help-block">{$sudemi_history.labels.empty|escape:'html':'UTF-8'}</p>
      {/if}
    {/if}

    {* Buyers' return requests (from the heartbeat). Reasons are plain text from sudemi: escaped. *}
    {if $sudemi_requests}
      {assign var=rql value=$sudemi_requests.labels}
      <div id="sudemi-requests">
        <h4>{$rql.title|escape:'html':'UTF-8'}</h4>
        {if $sudemi_requests.rows}
          <p class="help-block">{$rql.intro|escape:'html':'UTF-8'}</p>
          <table class="table" id="sudemi-request-list">
            <thead><tr>
              <th>{$rql.order|escape:'html':'UTF-8'}</th>
              <th>{$rql.reason|escape:'html':'UTF-8'}</th>
              <th>{$rql.respond_by|escape:'html':'UTF-8'}</th>
              <th></th>
            </tr></thead>
            <tbody>
            {foreach from=$sudemi_requests.rows item=rq}
              <tr>
                <td>{if $rq.local_id}<a href="{$rq.local_url|escape:'html':'UTF-8'}">#{$rq.local_id|intval} {$rq.reference|escape:'html':'UTF-8'}</a>{else}{$rql.no_local|escape:'html':'UTF-8'}{/if}<br><small><code>{$rq.short|escape:'html':'UTF-8'}</code> {$rql.requested|escape:'html':'UTF-8'} {$rq.requested|escape:'html':'UTF-8'}</small></td>
                <td style="white-space:pre-wrap;word-wrap:break-word">{if $rq.reason}{$rq.reason|escape:'html':'UTF-8'}{else}<em>{$rql.no_reason|escape:'html':'UTF-8'}</em>{/if}</td>
                <td><strong>{$rq.respond_by|escape:'html':'UTF-8'}</strong></td>
                <td>
                  <form method="post" action="{$sudemi_rq_action|escape:'html':'UTF-8'}" style="margin-bottom:.5em">
                    <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
                    <input type="hidden" name="sudemi_request_order" value="{$rq.order_id|escape:'html':'UTF-8'}" />
                    <button type="submit" name="sudemi_request_accept" class="btn btn-primary btn-sm">{$rql.accept|escape:'html':'UTF-8'}</button>
                  </form>
                  <form method="post" action="{$sudemi_rq_action|escape:'html':'UTF-8'}">
                    <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
                    <input type="hidden" name="sudemi_request_order" value="{$rq.order_id|escape:'html':'UTF-8'}" />
                    <label style="font-weight:normal">{$rql.reject_reason|escape:'html':'UTF-8'}
                      <textarea name="sudemi_request_reason" rows="2" maxlength="1000" required class="form-control"></textarea></label>
                    <button type="submit" name="sudemi_request_reject" class="btn btn-default btn-sm">{$rql.reject|escape:'html':'UTF-8'}</button>
                  </form>
                </td>
              </tr>
            {/foreach}
            </tbody>
          </table>
        {else}
          <p class="help-block">{$rql.empty|escape:'html':'UTF-8'}</p>
        {/if}
      </div>
    {/if}

    <h4>{l s='Delivery to sudemi buyers' mod='sudemi'}</h4>
    {if $sudemi_shipping_preview}
      <p>{l s='Shipping prices sent to sudemi (from your carriers; one item, your first product):' mod='sudemi'}
        {foreach from=$sudemi_shipping_preview item=rate name=rates}{$rate.country|escape:'html':'UTF-8'} {($rate.costMinor/100)|string_format:'%.2f'} ({$rate.carrier|escape:'html':'UTF-8'}){if !$smarty.foreach.rates.last}, {/if}{/foreach}</p>
      <p class="help-block">{l s='Countries without a fixed price (module-computed carriers, out-of-range weights or prices) are left out rather than guessed.' mod='sudemi'}</p>
    {else}
      <div class="alert alert-warning">{l s='No shipping price could be read from your carriers, so sudemi buyers cannot order. Give an active carrier a price or weight range for the zones you ship to.' mod='sudemi'}</div>
    {/if}
    <form method="post" action="{$sudemi_action|escape:'html':'UTF-8'}" class="form-inline">
      <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
      <p><strong>{l s='Carriers that deliver sudemi orders' mod='sudemi'}</strong> <span class="help-block">{l s='Leave pick-up-only carriers unticked: buyers would otherwise see their price as the shipping price.' mod='sudemi'}</span>
        {foreach from=$sudemi_carriers key=ref item=name}
          <label style="margin-right:1em"><input type="checkbox" name="sudemi_carriers[]" value="{$ref|intval}" {if $sudemi_chosen_carriers === null || in_array($ref, $sudemi_chosen_carriers)}checked{/if} /> {$name|escape:'html':'UTF-8'}</label>
        {/foreach}
      </p>
      <label>{l s='Days until you dispatch an order (at most)' mod='sudemi'} <input type="number" min="0" max="60" name="handling_days" value="{$sudemi_handling_days|intval}" class="form-control" style="width:6em" /></label>
      <label>{l s='Days the carrier takes (at most)' mod='sudemi'} <input type="number" min="0" max="60" name="transit_days" value="{$sudemi_transit_days|intval}" class="form-control" style="width:6em" /></label>
      <button type="submit" name="sudemi_delivery" class="btn btn-default">{l s='Save delivery settings' mod='sudemi'}</button>
      <p class="help-block">{l s='Buyers see the sum as the latest delivery estimate. Set your real times; the defaults are deliberately cautious.' mod='sudemi'}</p>
    </form>

    <h4>{l s='Set on sudemi, not here' mod='sudemi'}</h4>
    <p>{l s='Your legal name, country, trader status and VAT number decide what a buyer is owed, so they are confirmed once on sudemi and changed there. This module cannot edit them.' mod='sudemi'}</p>

    <form method="post" action="{$sudemi_action|escape:'html':'UTF-8'}" style="display:inline">
      <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
      <button type="submit" name="sudemi_sync" class="btn btn-default">{l s='Send the catalogue now' mod='sudemi'}</button>
    </form>
    <form method="post" action="{$sudemi_action|escape:'html':'UTF-8'}" style="display:inline">
      <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
      <button type="submit" name="sudemi_disconnect" class="btn btn-danger">{l s='Disconnect' mod='sudemi'}</button>
    </form>

  {elseif $sudemi_claim_url}
    <div class="alert alert-info">
      {l s='Almost there. Open the link below, sign in to sudemi and confirm that this shop is yours, then press Check again.' mod='sudemi'}
    </div>
    <p>
      <a class="btn btn-primary" target="_blank" rel="noopener noreferrer" href="{$sudemi_claim_url|escape:'html':'UTF-8'}">
        {l s='Confirm this shop on sudemi' mod='sudemi'}
      </a>
    </p>
    <form method="post" action="{$sudemi_action|escape:'html':'UTF-8'}">
      <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
      <button type="submit" name="sudemi_check" class="btn btn-default">{l s='Check again' mod='sudemi'}</button>
    </form>

  {else}
    <p>{l s='Connecting this shop lets an AI assistant find your products and send buyers to you. You confirm the connection on sudemi, signed in to your own account - this module never asks for your sudemi password.' mod='sudemi'}</p>
    <form method="post" action="{$sudemi_action|escape:'html':'UTF-8'}">
      <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
      <button type="submit" name="sudemi_connect" class="btn btn-primary" {if !$sudemi_supported}disabled{/if}>
        {l s='Connect this shop' mod='sudemi'}
      </button>
    </form>
  {/if}

  {if $sudemi_log}
    <h4>{l s='Recent activity' mod='sudemi'}</h4>
    <table class="table">
      {foreach from=$sudemi_log item=entry}
        <tr>
          <td style="width:14rem">{$entry.at|escape:'html':'UTF-8'}</td>
          <td>{$entry.message|escape:'html':'UTF-8'}</td>
        </tr>
      {/foreach}
    </table>
  {/if}
</div>

{* Support tickets with sudemi. Ticket bodies are plain text from sudemi: escaped, never HTML,
   with line breaks kept by white-space:pre-wrap. *}
<div class="panel" id="sudemi-support">
  <div class="panel-heading"><i class="icon-question-sign"></i> {$sudemi_support_title|escape:'html':'UTF-8'}</div>
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
