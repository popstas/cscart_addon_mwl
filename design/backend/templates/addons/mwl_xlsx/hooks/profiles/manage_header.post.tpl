{* Показываем колонку компании только если мы не внутри конкретной компании (MV/ULT) *}
{if !$runtime.company_id}
    <th class="left">{__("company")}</th>
{/if}
