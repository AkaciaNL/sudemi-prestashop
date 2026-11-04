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
          <p class="help-block">{l s='Fixed. sudemi checked that this shop answered from this address, and a listing tells a buyer where it came from — so changing it means connecting again from the new address.' mod='sudemi'}</p>
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
       being listed when the beats stop arriving, and from this side everything looks normal —
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
    <p>{l s='Connecting this shop lets an AI assistant find your products and send buyers to you. You confirm the connection on sudemi, signed in to your own account — this module never asks for your sudemi password.' mod='sudemi'}</p>
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
