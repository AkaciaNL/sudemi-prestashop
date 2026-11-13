{*
 * sudemi > Shop profile (1.4.0): what buyers see about this shop on sudemi. The name is changed
 * only through a support ticket (owner 2026-11-09); the rest is saved on sudemi from here.
 * Everything printed is escaped; the form carries the module's CSRF token.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *}
<div class="sudemi-ui" id="sudemi-profile">
{include file="./sudemi-header.tpl"}
{if !$sudemi.connected}
  <div class="sudemi-card"><div class="sudemi-card-body"><p class="sudemi-empty">{$sudemi_t.not_connected|escape:'html':'UTF-8'}</p></div></div>
{else}
  <p class="sudemi-intro">{$sudemi_t.profile_intro|escape:'html':'UTF-8'}</p>
  <div class="sudemi-grid2" style="grid-template-columns:minmax(0,2fr) minmax(0,1fr)">
    <div class="sudemi-card">
      <div class="sudemi-card-head"><h3>{$sudemi_t.profile_edit|escape:'html':'UTF-8'}</h3></div>
      <div class="sudemi-card-body">
        <form method="post" action="{$sudemi.self_url|escape:'html':'UTF-8'}" id="sudemi-profile-form">
          <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
          <div class="sudemi-form-row">
            <label>{$sudemi_t.profile_name|escape:'html':'UTF-8'}</label>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <input type="text" class="form-control" value="{$sudemi.name|escape:'html':'UTF-8'}" readonly style="max-width:24rem" id="sudemi-profile-name" />
              <a class="btn btn-default" href="{$sudemi.rename_url|escape:'html':'UTF-8'}" id="sudemi-rename">{$sudemi_t.profile_rename|escape:'html':'UTF-8'}</a>
            </div>
            <p class="help">{$sudemi_t.profile_name_help|escape:'html':'UTF-8'}</p>
          </div>
          <div class="sudemi-form-row">
            <label for="sudemi-description">{$sudemi_t.profile_description|escape:'html':'UTF-8'}</label>
            <textarea id="sudemi-description" name="sudemi_description" rows="5" maxlength="2000" class="form-control">{$sudemi.description|escape:'html':'UTF-8'}</textarea>
            <p class="help">{$sudemi_t.profile_description_help|escape:'html':'UTF-8'}</p>
          </div>
          <div class="sudemi-form-row">
            <label for="sudemi-support-email">{$sudemi_t.profile_email|escape:'html':'UTF-8'}</label>
            <input type="email" id="sudemi-support-email" name="sudemi_support_email" required maxlength="254" class="form-control" style="max-width:24rem" value="{$sudemi.support_email|escape:'html':'UTF-8'}" />
            <p class="help">{$sudemi_t.profile_email_help|escape:'html':'UTF-8'}</p>
          </div>
          <div class="sudemi-form-row">
            <label for="sudemi-returns-days">{$sudemi_t.profile_days|escape:'html':'UTF-8'}</label>
            <input type="number" id="sudemi-returns-days" name="sudemi_returns_days" min="14" max="365" required class="form-control" style="max-width:8rem" value="{$sudemi.returns_window_days|escape:'html':'UTF-8'}" />
            <p class="help">{$sudemi_t.profile_days_help|escape:'html':'UTF-8'}</p>
          </div>
          <button type="submit" name="sudemi_profile_save" value="1" class="btn btn-primary">{$sudemi_t.profile_save|escape:'html':'UTF-8'}</button>
        </form>
      </div>
    </div>
    <div class="sudemi-card">
      <div class="sudemi-card-head"><h3>{$sudemi_t.profile_fixed|escape:'html':'UTF-8'}</h3></div>
      <div class="sudemi-card-body">
        <dl class="sudemi-dl">
          <dt>{$sudemi_t.profile_address|escape:'html':'UTF-8'}</dt><dd><code>{$sudemi.origin|escape:'html':'UTF-8'}</code></dd>
          <dt>{$sudemi_t.profile_currency|escape:'html':'UTF-8'}</dt><dd>{$sudemi.currency|escape:'html':'UTF-8'}</dd>
        </dl>
        <p class="sudemi-muted" style="margin:12px 0 0;font-size:12px">{$sudemi_t.profile_fixed_help|escape:'html':'UTF-8'}</p>
      </div>
    </div>
  </div>
{/if}
</div>
