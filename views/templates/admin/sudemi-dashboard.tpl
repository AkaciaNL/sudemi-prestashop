{*
 * sudemi > Dashboard (1.4.0): statistics from sudemi (GET /integration/v1/stats). The charts are
 * SVG built by SudemiInsights::chart, which escapes every label itself; printed with nofilter.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *}
<div class="sudemi-ui" id="sudemi-dashboard">
{include file="./sudemi-header.tpl"}
{if !$sudemi.connected}
  <div class="sudemi-card"><div class="sudemi-card-body"><p class="sudemi-empty">{$sudemi_t.not_connected|escape:'html':'UTF-8'}</p></div></div>
{else}
  <div class="sudemi-card-head" style="border:0;padding:0 0 16px">
    <p class="sudemi-intro" style="margin:0">{$sudemi_t.dash_intro|escape:'html':'UTF-8'}</p>
    <div class="sudemi-tabs" id="sudemi-periods">
      {foreach from=$sudemi.periods item=p}
        <a href="{$p.url|escape:'html':'UTF-8'}" class="{if $p.active}active{/if}" data-days="{$p.days|intval}">{$p.label|escape:'html':'UTF-8'}</a>
      {/foreach}
    </div>
  </div>
  {if $sudemi.ok}
    <div class="sudemi-kpis" id="sudemi-kpis">
      {foreach from=$sudemi.kpis item=k}
        <div class="sudemi-kpi{if $k.main} sudemi-kpi-main{/if}" data-kpi="{$k.key|escape:'html':'UTF-8'}">
          <p class="sudemi-kpi-label">{$k.label|escape:'html':'UTF-8'}</p>
          <p class="sudemi-kpi-value">{$k.value|escape:'html':'UTF-8'}</p>
          {if $k.help}<p class="sudemi-kpi-help">{$k.help|escape:'html':'UTF-8'}</p>{/if}
        </div>
      {/foreach}
    </div>
    <p class="sudemi-muted" style="margin:-8px 0 20px;font-size:12px">{$sudemi.refunded|escape:'html':'UTF-8'} &middot; {$sudemi.from|escape:'html':'UTF-8'} - {$sudemi.to|escape:'html':'UTF-8'} (UTC)</p>

    <div class="sudemi-grid2">
      <div class="sudemi-card">
        <div class="sudemi-card-head"><h3>{$sudemi_t.chart_seen|escape:'html':'UTF-8'}</h3>
          <div class="sudemi-legend"><span class="bar">{$sudemi_t.kpi_impressions|escape:'html':'UTF-8'}</span><span class="line">{$sudemi_t.kpi_views|escape:'html':'UTF-8'}</span></div></div>
        <div class="sudemi-card-body" id="sudemi-chart-seen">{$sudemi.chart_seen nofilter}</div>
      </div>
      <div class="sudemi-card">
        <div class="sudemi-card-head"><h3>{$sudemi_t.chart_sold|escape:'html':'UTF-8'}</h3>
          <div class="sudemi-legend"><span class="bar">{$sudemi_t.kpi_orders|escape:'html':'UTF-8'}</span><span class="line">{$sudemi_t.kpi_cart|escape:'html':'UTF-8'}</span></div></div>
        <div class="sudemi-card-body" id="sudemi-chart-sold">{$sudemi.chart_sold nofilter}</div>
      </div>
    </div>

    <div class="sudemi-card" id="sudemi-top-products">
      <div class="sudemi-card-head"><h3>{$sudemi_t.top_title|escape:'html':'UTF-8'}</h3><a href="{$sudemi.products_url|escape:'html':'UTF-8'}">{$sudemi_t.listings_open|escape:'html':'UTF-8'}</a></div>
      {if $sudemi.top}
        <div class="sudemi-scroll"><table class="sudemi-table">
          <thead><tr>
            <th>{$sudemi_t.col_product|escape:'html':'UTF-8'}</th>
            <th class="num">{$sudemi_t.col_shown|escape:'html':'UTF-8'}</th>
            <th class="num">{$sudemi_t.col_views|escape:'html':'UTF-8'}</th>
            <th class="num">{$sudemi_t.col_cart|escape:'html':'UTF-8'}</th>
            <th class="num">{$sudemi_t.col_orders|escape:'html':'UTF-8'}</th>
            <th class="num">{$sudemi_t.col_units|escape:'html':'UTF-8'}</th>
            <th class="num">{$sudemi_t.col_sales|escape:'html':'UTF-8'}</th>
          </tr></thead>
          <tbody>
          {foreach from=$sudemi.top item=p}
            <tr>
              <td>{$p.title|escape:'html':'UTF-8'}</td>
              <td class="num">{$p.impressions|escape:'html':'UTF-8'}</td>
              <td class="num">{$p.views|escape:'html':'UTF-8'}</td>
              <td class="num">{$p.cart|escape:'html':'UTF-8'}</td>
              <td class="num">{$p.orders|escape:'html':'UTF-8'}</td>
              <td class="num">{$p.units|escape:'html':'UTF-8'}</td>
              <td class="num"><strong>{$p.sales|escape:'html':'UTF-8'}</strong></td>
            </tr>
          {/foreach}
          </tbody>
        </table></div>
      {else}
        <p class="sudemi-empty">{$sudemi_t.no_data|escape:'html':'UTF-8'}</p>
      {/if}
    </div>

    <div class="sudemi-grid2">
      <div class="sudemi-card" id="sudemi-listing-summary">
        <div class="sudemi-card-head"><h3>{$sudemi_t.listings_title|escape:'html':'UTF-8'}</h3><a href="{$sudemi.products_url|escape:'html':'UTF-8'}">{$sudemi_t.listings_open|escape:'html':'UTF-8'}</a></div>
        <div class="sudemi-card-body">
          <p style="margin:0">{$sudemi.listings_line|escape:'html':'UTF-8'}</p>
          <div class="sudemi-bar"><span style="width:{$sudemi.listings_pct|intval}%"></span></div>
          {if $sudemi.listings_listed < $sudemi.listings_total}<p style="margin:0"><a href="{$sudemi.not_listed_url|escape:'html':'UTF-8'}">{$sudemi_t.see_not_listed|escape:'html':'UTF-8'}</a></p>{/if}
        </div>
      </div>
      <div class="sudemi-card" id="sudemi-status-summary">
        <div class="sudemi-card-head"><h3>{$sudemi_t.status_title|escape:'html':'UTF-8'}</h3></div>
        <div class="sudemi-card-body">
          {if $sudemi.statuses}
            {foreach from=$sudemi.statuses item=s}
              <span class="sudemi-badge {if $s.status == 'DELIVERED' || $s.status == 'PAID' || $s.status == 'SHIPPED'}sudemi-badge-ok{elseif $s.status == 'AWAITING_SELLER' || $s.status == 'ACCEPTED'}sudemi-badge-warn{elseif $s.status == 'REFUNDED' || $s.status == 'CANCELLED' || $s.status == 'DECLINED'}sudemi-badge-bad{/if}" style="margin:0 6px 6px 0" data-status="{$s.status|escape:'html':'UTF-8'}">{$s.label|escape:'html':'UTF-8'}: {$s.count|intval}</span>
            {/foreach}
          {else}
            <p class="sudemi-empty">{$sudemi_t.no_data|escape:'html':'UTF-8'}</p>
          {/if}
        </div>
      </div>
    </div>

    <div class="sudemi-grid2">
      <div class="sudemi-card" id="sudemi-latest-orders">
        <div class="sudemi-card-head"><h3>{$sudemi_t.latest_title|escape:'html':'UTF-8'}</h3><a href="{$sudemi.orders_url|escape:'html':'UTF-8'}">{$sudemi_t.all_orders|escape:'html':'UTF-8'}</a></div>
        {if $sudemi.latest}
          <div class="sudemi-scroll"><table class="sudemi-table">
            <tbody>
            {foreach from=$sudemi.latest item=o}
              <tr>
                <td><a href="{$o.url|escape:'html':'UTF-8'}"><code>{$o.short|escape:'html':'UTF-8'}</code></a><br><small class="sudemi-muted">{$o.date|escape:'html':'UTF-8'}</small></td>
                <td>{$o.items|escape:'html':'UTF-8'}<br><span class="sudemi-badge">{$o.status_label|escape:'html':'UTF-8'}</span></td>
                <td class="num"><strong>{$o.total|escape:'html':'UTF-8'}</strong></td>
              </tr>
            {/foreach}
            </tbody>
          </table></div>
        {else}
          <p class="sudemi-empty">{$sudemi_t.no_orders_yet|escape:'html':'UTF-8'}</p>
        {/if}
      </div>
      <div class="sudemi-card" id="sudemi-countries">
        <div class="sudemi-card-head"><h3>{$sudemi_t.countries_title|escape:'html':'UTF-8'}</h3></div>
        {if $sudemi.countries}
          <table class="sudemi-table">
            <thead><tr><th>{$sudemi_t.col_country|escape:'html':'UTF-8'}</th><th class="num">{$sudemi_t.col_orders|escape:'html':'UTF-8'}</th><th class="num">{$sudemi_t.col_sales|escape:'html':'UTF-8'}</th></tr></thead>
            <tbody>
            {foreach from=$sudemi.countries item=c}
              <tr><td>{$c.name|escape:'html':'UTF-8'}</td><td class="num">{$c.orders|intval}</td><td class="num">{$c.sales|escape:'html':'UTF-8'}</td></tr>
            {/foreach}
            </tbody>
          </table>
        {else}
          <p class="sudemi-empty">{$sudemi_t.no_data|escape:'html':'UTF-8'}</p>
        {/if}
      </div>
    </div>
  {/if}
{/if}
</div>
