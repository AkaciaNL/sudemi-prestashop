{*
 * Orders > sudemi orders > one order, as sudemi's order page shows it, with the actions sudemi
 * allows now (allowed_actions). Everything printed is escaped; the forms carry the module's CSRF
 * token, and refund and cancel an idempotency key so a repeated post moves no money twice.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *}
<div id="sudemi-order">
{foreach from=$sudemi_messages item=m}
  <div class="alert alert-{if $m.type == 'error'}danger{elseif $m.type == 'success'}success{elseif $m.type == 'info'}info{else}warning{/if} sudemi-message">{$m.text|escape:'html':'UTF-8'}</div>
{/foreach}
{if !$sudemi.connected}
  <div class="alert alert-warning">{$sudemi_t.not_connected|escape:'html':'UTF-8'}</div>
{else}
  <p><a href="{$sudemi.back_url|escape:'html':'UTF-8'}">{$sudemi_t.back|escape:'html':'UTF-8'}</a></p>
  {if $sudemi.order}
  {assign var=o value=$sudemi.order}
  <div class="panel" data-sudemi-order="{$o.id|escape:'html':'UTF-8'}" data-status="{$o.status|escape:'html':'UTF-8'}">
    <div class="panel-heading">{$o.title|escape:'html':'UTF-8'}: {$o.status_label|escape:'html':'UTF-8'}</div>
    <p><code>{$o.id|escape:'html':'UTF-8'}</code></p>
    <table class="table" id="sudemi-facts">
      {foreach from=$o.facts key=label item=value}
        <tr><th style="width:16rem">{$label|escape:'html':'UTF-8'}</th><td style="white-space:pre-wrap">{$value|escape:'html':'UTF-8'}</td></tr>
      {/foreach}
      <tr><th>{$sudemi_t.local_order|escape:'html':'UTF-8'}</th>
        <td id="sudemi-local">{if $o.local_url}<a href="{$o.local_url|escape:'html':'UTF-8'}">{$o.local_ref|escape:'html':'UTF-8'}</a>{else}{$sudemi_t.no_local|escape:'html':'UTF-8'}{/if}</td></tr>
    </table>

    <h4>{$sudemi_t.lines|escape:'html':'UTF-8'}</h4>
    <table class="table" id="sudemi-lines">
      <thead><tr>
        <th>{$sudemi_t.product|escape:'html':'UTF-8'}</th><th>{$sudemi_t.your_id|escape:'html':'UTF-8'}</th>
        <th>{$sudemi_t.quantity|escape:'html':'UTF-8'}</th><th>{$sudemi_t.unit_price|escape:'html':'UTF-8'}</th><th>{$sudemi_t.line_total|escape:'html':'UTF-8'}</th>
      </tr></thead>
      <tbody>
      {foreach from=$o.lines item=l}
        <tr><td>{$l.title|escape:'html':'UTF-8'}{if $l.exclusion}<br /><small>{$l.exclusion|escape:'html':'UTF-8'}</small>{/if}</td>
          <td><code>{$l.external_id|escape:'html':'UTF-8'}</code></td><td>{$l.quantity|intval}</td><td>{$l.unit|escape:'html':'UTF-8'}</td><td>{$l.total|escape:'html':'UTF-8'}</td></tr>
      {/foreach}
      </tbody>
    </table>

    <div class="row">
      <div class="col-md-6">
        <h4>{$sudemi_t.money|escape:'html':'UTF-8'}</h4>
        <table class="table" id="sudemi-money">
          {foreach from=$o.money key=label item=value}<tr><th>{$label|escape:'html':'UTF-8'}</th><td>{$value|escape:'html':'UTF-8'}</td></tr>{/foreach}
        </table>
      </div>
      <div class="col-md-6">
        <h4>{$sudemi_t.payout|escape:'html':'UTF-8'}</h4>
        <table class="table" id="sudemi-payout">
          {foreach from=$o.payout key=label item=value}<tr><th>{$label|escape:'html':'UTF-8'}</th><td>{$value|escape:'html':'UTF-8'}</td></tr>{/foreach}
        </table>
        {if $o.payout_note}<p class="help-block">{$o.payout_note|escape:'html':'UTF-8'}</p>{/if}
      </div>
    </div>

    {if $o.request}
      <div class="alert alert-warning" id="sudemi-request">
        <h4>{$sudemi_t.request_title|escape:'html':'UTF-8'}: {$o.request.kind|escape:'html':'UTF-8'}</h4>
        <p style="white-space:pre-wrap;word-wrap:break-word">{$o.request.reason|escape:'html':'UTF-8'}</p>
        <p>{$o.request.requested|escape:'html':'UTF-8'}{if $o.request.respond_by} / {$sudemi_t.respond_by|escape:'html':'UTF-8'}: <strong>{$o.request.respond_by|escape:'html':'UTF-8'}</strong>{/if}</p>
        {if !empty($o.can.request_accept)}
          <form method="post" action="{$sudemi.action_url|escape:'html':'UTF-8'}" style="display:inline-block;margin-right:1em">
            <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
            <button type="submit" name="sudemi_do" value="request_accept" class="btn btn-primary">{$sudemi_t.request_accept|escape:'html':'UTF-8'}</button>
          </form>
        {/if}
        {if !empty($o.can.request_reject)}
          <form method="post" action="{$sudemi.action_url|escape:'html':'UTF-8'}" style="margin-top:1em">
            <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
            <label>{$sudemi_t.reason|escape:'html':'UTF-8'}</label>
            <textarea name="sudemi_reason" rows="2" maxlength="500" required class="form-control"></textarea>
            <button type="submit" name="sudemi_do" value="request_reject" class="btn btn-default">{$sudemi_t.request_reject|escape:'html':'UTF-8'}</button>
          </form>
        {/if}
      </div>
    {/if}

    <h4>{$sudemi_t.actions|escape:'html':'UTF-8'}</h4>
    <div id="sudemi-actions">
    {if $o.nothing_to_do}<p class="help-block">{$sudemi_t.no_actions|escape:'html':'UTF-8'}</p>{/if}
    {if !empty($o.can.accept)}
      <form method="post" action="{$sudemi.action_url|escape:'html':'UTF-8'}" style="display:inline-block;margin-right:1em">
        <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
        <button type="submit" name="sudemi_do" value="accept" class="btn btn-primary">{$sudemi_t.do_accept|escape:'html':'UTF-8'}</button>
      </form>
    {/if}
    {if !empty($o.can.reject)}
      <form method="post" action="{$sudemi.action_url|escape:'html':'UTF-8'}" class="well">
        <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
        <label>{$sudemi_t.reason|escape:'html':'UTF-8'}</label>
        <textarea name="sudemi_reason" rows="2" maxlength="500" required class="form-control"></textarea>
        <button type="submit" name="sudemi_do" value="reject" class="btn btn-default">{$sudemi_t.do_reject|escape:'html':'UTF-8'}</button>
      </form>
    {/if}
    {if !empty($o.can.shipped)}
      <form method="post" action="{$sudemi.action_url|escape:'html':'UTF-8'}" class="well form-inline">
        <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
        <label>{$sudemi_t.carrier|escape:'html':'UTF-8'} <input type="text" name="sudemi_carrier" maxlength="100" class="form-control" list="sudemi-carrier-list" /></label>
        <label>{$sudemi_t.tracking_code|escape:'html':'UTF-8'} <input type="text" name="sudemi_tracking" maxlength="100" class="form-control" /></label>
        <datalist id="sudemi-carrier-list">{foreach from=$sudemi.carriers item=c}<option value="{$c|escape:'html':'UTF-8'}"></option>{/foreach}</datalist>
        <button type="submit" name="sudemi_do" value="shipped" class="btn btn-primary">{$sudemi_t.do_shipped|escape:'html':'UTF-8'}</button>
      </form>
    {/if}
    {if !empty($o.can.update_tracking)}
      <form method="post" action="{$sudemi.action_url|escape:'html':'UTF-8'}" class="well form-inline" id="sudemi-update-tracking">
        <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
        <p class="help-block">{$sudemi_t.update_tracking_help|escape:'html':'UTF-8'}</p>
        <label>{$sudemi_t.carrier|escape:'html':'UTF-8'} <input type="text" name="sudemi_carrier" maxlength="100" class="form-control" list="sudemi-carrier-list-t" value="{$o.tracking_carrier|escape:'html':'UTF-8'}" /></label>
        <label>{$sudemi_t.tracking_code|escape:'html':'UTF-8'} <input type="text" name="sudemi_tracking" maxlength="100" required class="form-control" value="{$o.tracking_code|escape:'html':'UTF-8'}" /></label>
        <datalist id="sudemi-carrier-list-t">{foreach from=$sudemi.carriers item=c}<option value="{$c|escape:'html':'UTF-8'}"></option>{/foreach}</datalist>
        <button type="submit" name="sudemi_do" value="update_tracking" class="btn btn-default">{$sudemi_t.do_update_tracking|escape:'html':'UTF-8'}</button>
      </form>
    {/if}
    {if !empty($o.can.delivered)}
      <form method="post" action="{$sudemi.action_url|escape:'html':'UTF-8'}" style="display:inline-block;margin-right:1em">
        <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
        <button type="submit" name="sudemi_do" value="delivered" class="btn btn-default">{$sudemi_t.do_delivered|escape:'html':'UTF-8'}</button>
      </form>
    {/if}
    {if !empty($o.can.refund)}
      <form method="post" action="{$sudemi.action_url|escape:'html':'UTF-8'}" class="well">
        <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
        <input type="hidden" name="sudemi_idem" value="r{$sudemi.idempotency|escape:'html':'UTF-8'}" />
        <p class="help-block">{$o.refund_help|escape:'html':'UTF-8'}</p>
        <label>{$sudemi_t.amount|escape:'html':'UTF-8'} ({$o.currency|escape:'html':'UTF-8'}) <input type="text" name="sudemi_amount" inputmode="decimal" maxlength="12" required class="form-control" /></label>
        <label>{$sudemi_t.reason|escape:'html':'UTF-8'}</label>
        <textarea name="sudemi_reason" rows="2" maxlength="500" required class="form-control"></textarea>
        <button type="submit" name="sudemi_do" value="refund" class="btn btn-default">{$sudemi_t.do_refund|escape:'html':'UTF-8'}</button>
      </form>
    {/if}
    {if !empty($o.can.cancel)}
      <form method="post" action="{$sudemi.action_url|escape:'html':'UTF-8'}" class="well">
        <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
        <input type="hidden" name="sudemi_idem" value="c{$sudemi.idempotency|escape:'html':'UTF-8'}" />
        <p class="help-block">{$sudemi_t.cancel_help|escape:'html':'UTF-8'}</p>
        <label>{$sudemi_t.reason|escape:'html':'UTF-8'}</label>
        <textarea name="sudemi_reason" rows="2" maxlength="500" required class="form-control"></textarea>
        <button type="submit" name="sudemi_do" value="cancel" class="btn btn-danger">{$sudemi_t.do_cancel|escape:'html':'UTF-8'}</button>
      </form>
    {/if}
    </div>

    {if $o.events}
      <h4>{$sudemi_t.timeline|escape:'html':'UTF-8'}</h4>
      <ol id="sudemi-timeline">
        {foreach from=$o.events item=e}
          <li><small>{$e.at|escape:'html':'UTF-8'}</small> <strong>{$e.title|escape:'html':'UTF-8'}</strong>{if $e.detail} {$e.detail|escape:'html':'UTF-8'}{/if}</li>
        {/foreach}
      </ol>
    {/if}
  </div>
  {/if}
{/if}
</div>
