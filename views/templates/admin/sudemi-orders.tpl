{*
 * Orders > sudemi orders: payouts, filters, the order table with its bulk actions, the export.
 * Everything printed is escaped; the forms carry the module's CSRF token.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *}
<div class="sudemi-ui" id="sudemi-orders">
{include file="./sudemi-header.tpl"}
{if !$sudemi.connected}
  <div class="alert alert-warning">{$sudemi_t.not_connected|escape:'html':'UTF-8'}</div>
{else}
  <div class="panel">
    <div class="panel-heading">{$sudemi_t.orders_title|escape:'html':'UTF-8'}</div>
    <p>{$sudemi_t.orders_intro|escape:'html':'UTF-8'}</p>
    {if $sudemi.auto_off}<div class="alert alert-warning" id="sudemi-auto-off">{$sudemi_t.auto_off|escape:'html':'UTF-8'}</div>{/if}

    <form method="get" action="index.php" class="form-inline" id="sudemi-filters" style="margin-bottom:1em">
      <input type="hidden" name="controller" value="{$sudemi.form_controller|escape:'html':'UTF-8'}" />
      <input type="hidden" name="token" value="{$sudemi.form_token|escape:'html':'UTF-8'}" />
      <label>{$sudemi_t.filter_status|escape:'html':'UTF-8'}
        <select name="status" class="form-control">
          <option value="">{$sudemi_t.all_statuses|escape:'html':'UTF-8'}</option>
          {foreach from=$sudemi.statuses key=code item=label}
            <option value="{$code|escape:'html':'UTF-8'}"{if $sudemi.filters.status == $code} selected{/if}>{$label|escape:'html':'UTF-8'}</option>
          {/foreach}
        </select>
      </label>
      <label>{$sudemi_t.from|escape:'html':'UTF-8'} <input type="date" name="from" class="form-control" value="{$sudemi.filters.from|escape:'html':'UTF-8'}" /></label>
      <label>{$sudemi_t.to|escape:'html':'UTF-8'} <input type="date" name="to" class="form-control" value="{$sudemi.filters.to|escape:'html':'UTF-8'}" /></label>
      <label>{$sudemi_t.search|escape:'html':'UTF-8'} <input type="text" name="q" class="form-control" maxlength="100" placeholder="{$sudemi_t.search_help|escape:'html':'UTF-8'}" value="{$sudemi.filters.q|escape:'html':'UTF-8'}" /></label>
      <label>{$sudemi_t.sort|escape:'html':'UTF-8'}
        <select name="sort" class="form-control">
          {foreach from=['placed', 'total', 'status'] item=s}
            <option value="{$s|escape:'html':'UTF-8'}"{if $sudemi.filters.sort == $s} selected{/if}>{$sudemi_t["sort_`$s`"]|escape:'html':'UTF-8'}</option>
          {/foreach}
        </select>
        <select name="dir" class="form-control">
          <option value="desc"{if $sudemi.filters.dir == 'desc'} selected{/if}>{$sudemi_t.dir_desc|escape:'html':'UTF-8'}</option>
          <option value="asc"{if $sudemi.filters.dir == 'asc'} selected{/if}>{$sudemi_t.dir_asc|escape:'html':'UTF-8'}</option>
        </select>
      </label>
      <button type="submit" class="btn btn-primary">{$sudemi_t.apply|escape:'html':'UTF-8'}</button>
      <a class="btn btn-default" href="{$sudemi.reset_url|escape:'html':'UTF-8'}">{$sudemi_t.reset|escape:'html':'UTF-8'}</a>
    </form>

    <form method="post" action="{$sudemi.self_url|escape:'html':'UTF-8'}" id="sudemi-bulk">
      <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
      {if $sudemi.rows}
        <div class="table-responsive">
        <table class="table" id="sudemi-order-table">
          <thead><tr>
            <th></th>
            <th>{$sudemi_t.col_order|escape:'html':'UTF-8'}</th>
            <th>{$sudemi_t.col_date|escape:'html':'UTF-8'}</th>
            <th>{$sudemi_t.col_items|escape:'html':'UTF-8'}</th>
            <th>{$sudemi_t.col_country|escape:'html':'UTF-8'}</th>
            <th>{$sudemi_t.col_status|escape:'html':'UTF-8'}</th>
            <th>{$sudemi_t.col_total|escape:'html':'UTF-8'}</th>
            <th>{$sudemi_t.col_net|escape:'html':'UTF-8'}</th>
            <th>{$sudemi_t.col_refunded|escape:'html':'UTF-8'}</th>
            <th>{$sudemi_t.col_tracking|escape:'html':'UTF-8'}</th>
            <th>{$sudemi_t.col_payout|escape:'html':'UTF-8'}</th>
            <th>{$sudemi_t.col_local|escape:'html':'UTF-8'}</th>
          </tr></thead>
          <tbody>
          {foreach from=$sudemi.rows item=row}
            <tr data-sudemi-order="{$row.id|escape:'html':'UTF-8'}" data-status="{$row.status|escape:'html':'UTF-8'}">
              <td><input type="checkbox" name="sudemi_ids[]" value="{$row.id|escape:'html':'UTF-8'}" /></td>
              <td><a href="{$row.url|escape:'html':'UTF-8'}"><code>{$row.short|escape:'html':'UTF-8'}</code></a></td>
              <td>{$row.date|escape:'html':'UTF-8'}</td>
              <td>{$row.items|escape:'html':'UTF-8'}</td>
              <td>{$row.country|escape:'html':'UTF-8'}</td>
              <td><span class="sudemi-badge {if $row.status == 'DELIVERED' || $row.status == 'PAID' || $row.status == 'SHIPPED'}sudemi-badge-ok{elseif $row.status == 'AWAITING_SELLER' || $row.status == 'ACCEPTED'}sudemi-badge-warn{elseif $row.status == 'REFUNDED' || $row.status == 'CANCELLED' || $row.status == 'DECLINED'}sudemi-badge-bad{/if}">{$row.status_label|escape:'html':'UTF-8'}</span>{if $row.respond_by}<br /><small>{$sudemi_t.respond_by|escape:'html':'UTF-8'}: {$row.respond_by|escape:'html':'UTF-8'}</small>{/if}</td>
              <td>{$row.total|escape:'html':'UTF-8'}</td>
              <td>{$row.net|escape:'html':'UTF-8'}</td>
              <td>{$row.refunded|escape:'html':'UTF-8'}</td>
              <td>{if $row.can_ship}<input type="text" class="form-control input-sm" name="sudemi_tracking[{$row.id|escape:'html':'UTF-8'}]" maxlength="100" placeholder="{$sudemi_t.tracking_code|escape:'html':'UTF-8'}" />{else}{$row.tracking|escape:'html':'UTF-8'}{/if}</td>
              <td>{$row.payout|escape:'html':'UTF-8'}</td>
              <td>{if $row.local_url}<a href="{$row.local_url|escape:'html':'UTF-8'}">{$row.local_ref|escape:'html':'UTF-8'}</a>{else}{$sudemi_t.none|escape:'html':'UTF-8'}{/if}</td>
            </tr>
          {/foreach}
          </tbody>
        </table>
        </div>
        <p>{$sudemi.shown|escape:'html':'UTF-8'}
          {if $sudemi.prev_url}<a class="btn btn-default btn-sm" href="{$sudemi.prev_url|escape:'html':'UTF-8'}">{$sudemi_t.previous|escape:'html':'UTF-8'}</a>{/if}
          {if $sudemi.next_url}<a class="btn btn-default btn-sm" href="{$sudemi.next_url|escape:'html':'UTF-8'}">{$sudemi_t.next|escape:'html':'UTF-8'}</a>{/if}
        </p>
        <fieldset class="well">
          <legend>{$sudemi_t.bulk_title|escape:'html':'UTF-8'}</legend>
          <div class="form-group">
            <label for="sudemi-action">{$sudemi_t.bulk_action|escape:'html':'UTF-8'}</label>
            <select id="sudemi-action" name="sudemi_action" class="form-control">
              <option value="">{$sudemi_t.bulk_choose|escape:'html':'UTF-8'}</option>
              <option value="accept">{$sudemi_t.do_accept|escape:'html':'UTF-8'}</option>
              <option value="reject">{$sudemi_t.do_reject|escape:'html':'UTF-8'}</option>
              <option value="shipped">{$sudemi_t.do_shipped|escape:'html':'UTF-8'}</option>
              <option value="delivered">{$sudemi_t.do_delivered|escape:'html':'UTF-8'}</option>
            </select>
          </div>
          <div class="form-group">
            <label for="sudemi-reason">{$sudemi_t.reason|escape:'html':'UTF-8'} ({$sudemi_t.do_reject|escape:'html':'UTF-8'})</label>
            <textarea id="sudemi-reason" name="sudemi_reason" rows="2" maxlength="500" class="form-control"></textarea>
          </div>
          <div class="form-group">
            <label for="sudemi-carrier">{$sudemi_t.carrier|escape:'html':'UTF-8'} ({$sudemi_t.do_shipped|escape:'html':'UTF-8'})</label>
            <input type="text" id="sudemi-carrier" name="sudemi_carrier" maxlength="100" class="form-control" list="sudemi-carrier-list" />
            <datalist id="sudemi-carrier-list">{foreach from=$sudemi.carriers item=c}<option value="{$c|escape:'html':'UTF-8'}"></option>{/foreach}</datalist>
          </div>
          <button type="submit" name="sudemi_bulk" value="1" class="btn btn-primary">{$sudemi_t.bulk_send|escape:'html':'UTF-8'}</button>
        </fieldset>
      {else}
        <p class="help-block">{$sudemi_t.no_orders|escape:'html':'UTF-8'}</p>
      {/if}
    </form>

    <form method="get" action="index.php" class="form-inline" id="sudemi-export">
      <strong>{$sudemi_t.export_title|escape:'html':'UTF-8'}</strong>
      <input type="hidden" name="controller" value="{$sudemi.form_controller|escape:'html':'UTF-8'}" />
      <input type="hidden" name="token" value="{$sudemi.form_token|escape:'html':'UTF-8'}" />
      <input type="hidden" name="sudemi_export" value="1" />
      {foreach from=['status', 'from', 'to', 'q', 'sort', 'dir'] item=f}
        {if $sudemi.filters[$f] != ''}<input type="hidden" name="{$f|escape:'html':'UTF-8'}" value="{$sudemi.filters[$f]|escape:'html':'UTF-8'}" />{/if}
      {/foreach}
      <label>{$sudemi_t.export_lang|escape:'html':'UTF-8'}
        <select name="lang" class="form-control">
          {foreach from=$sudemi.languages item=l}<option value="{$l|escape:'html':'UTF-8'}"{if $l == $sudemi.lang} selected{/if}>{$l|escape:'html':'UTF-8'}</option>{/foreach}
        </select>
      </label>
      <label><input type="checkbox" name="decimal" value="comma" /> {$sudemi_t.export_decimal|escape:'html':'UTF-8'}</label>
      <button type="submit" class="btn btn-default">{$sudemi_t.export_send|escape:'html':'UTF-8'}</button>
    </form>
  </div>

  <div class="panel" id="sudemi-payouts">
    <div class="panel-heading">{$sudemi_t.payouts_title|escape:'html':'UTF-8'}</div>
    {if $sudemi.payouts === null}
      <div class="alert alert-warning">{$sudemi_t.unreachable|escape:'html':'UTF-8'}</div>
    {else}
      <p><strong>{$sudemi_t.payouts_account|escape:'html':'UTF-8'}:</strong> {$sudemi.payouts.account|escape:'html':'UTF-8'}</p>
      <p class="help-block">{$sudemi.payouts.delay|escape:'html':'UTF-8'}</p>
      {if $sudemi.payouts.rows}
        <table class="table">
          <thead><tr>
            <th>{$sudemi_t.payouts_currency|escape:'html':'UTF-8'}</th>
            <th>{$sudemi_t.payouts_paid|escape:'html':'UTF-8'}</th>
            <th>{$sudemi_t.payouts_outstanding|escape:'html':'UTF-8'}</th>
            <th>{$sudemi_t.payouts_waiting|escape:'html':'UTF-8'}</th>
            <th>{$sudemi_t.payouts_scheduled|escape:'html':'UTF-8'}</th>
            <th>{$sudemi_t.payouts_processing|escape:'html':'UTF-8'}</th>
            <th>{$sudemi_t.payouts_held|escape:'html':'UTF-8'}</th>
            <th>{$sudemi_t.payouts_next|escape:'html':'UTF-8'}</th>
          </tr></thead>
          <tbody>
          {foreach from=$sudemi.payouts.rows item=p}
            <tr data-currency="{$p.currency|escape:'html':'UTF-8'}">
              <td>{$p.currency|escape:'html':'UTF-8'}</td><td>{$p.paid|escape:'html':'UTF-8'}</td><td>{$p.outstanding|escape:'html':'UTF-8'}</td>
              <td>{$p.waiting|escape:'html':'UTF-8'}</td><td>{$p.scheduled|escape:'html':'UTF-8'}</td><td>{$p.processing|escape:'html':'UTF-8'}</td>
              <td>{$p.held|escape:'html':'UTF-8'}</td><td>{$p.next|escape:'html':'UTF-8'}</td>
            </tr>
          {/foreach}
          </tbody>
        </table>
      {else}
        <p class="help-block">{$sudemi_t.payouts_none|escape:'html':'UTF-8'}</p>
      {/if}
    {/if}
  </div>
{/if}
</div>
