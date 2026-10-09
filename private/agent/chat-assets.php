<?php
declare(strict_types=1);
// Private, read-only catalogue presentation. No network requests or model calls.
function chatAssetName(mixed $value): ?string {
    if (!is_string($value) || strlen($value)>1024) return null;
    $name=trim(preg_replace('/[\s\p{C}]+/u',' ',$value)??'');
    $name=preg_replace('/\s*\((?:bStocks|Ondo|xStocks)\)\s*$/i','',$name)??$name;
    $name=preg_replace('/(?:,?\s+)(?:Inc\.?|Incorporated|Corp\.?|Corporation|Ltd\.?|Limited|PLC)\s*$/i','',$name)??$name;
    $chars=preg_split('//u',$name,-1,PREG_SPLIT_NO_EMPTY);
    if(!is_array($chars)||!$chars)return null;
    return count($chars)>64?rtrim(implode('',array_slice($chars,0,63))).'…':$name;
}
function chatAssetLogo(mixed $value): ?string {
    if(!is_string($value)||strlen($value)>1024)return null;
    if(str_starts_with($value,'/images/web3-data/public/token/logos/'))$value='https://bin.bnbstatic.com'.$value;
    $u=parse_url($value);
    if(!is_array($u)||($u['scheme']??'')!=='https'||isset($u['user'])||isset($u['pass'])||isset($u['port'])||isset($u['query'])||isset($u['fragment'])
      ||!in_array($u['host']??'',['onchainos.bnbstatic.com','bin.bnbstatic.com','public.bnbstatic.com'],true)
      ||!preg_match('~\A/images/web3-data/public/token/logos/[A-Za-z0-9._-]+\.(?:png|jpg|jpeg|webp|svg)\z~i',$u['path']??''))return null;
    return $value;
}
function chatAssetFromRows(string $ticker,array $rows): array {
    $asset=['ticker'=>$ticker,'name'=>null,'logo_url'=>null,'provider'=>null,'symbol'=>null];
    foreach($rows as $row){
        if($asset['provider']===null){$asset['provider']=$row['provider']??null;$asset['symbol']=$row['symbol']??null;}
        if($asset['name']===null)$asset['name']=chatAssetName($row['underlying_name']??null);
        $meta=json_decode((string)($row['metadata_json']??''),true,16);
        if(!is_array($meta))$meta=[];
        if($asset['name']===null)$asset['name']=chatAssetName($meta['underlyingName']??null);
        // Never borrow another representation's logo for a named technical provider.
        if(($row['provider']??null)===$asset['provider']&&$asset['logo_url']===null){
            foreach(['tokenLogoUrl','icon'] as $key){$logo=chatAssetLogo($meta[$key]??null);if($logo!==null){$asset['logo_url']=$logo;break;}}
        }
    }
    if($asset['name']===null && isset($rows[0]))$asset['name']=chatAssetName($rows[0]['token_name']??null);
    return $asset;
}
function chatAsset(PDO $db,string $ticker,?string $preferredProvider=null): array {
    $fallback=['ticker'=>$ticker,'name'=>null,'logo_url'=>null,'provider'=>null,'symbol'=>null];
    if(!preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,15}\z/',$ticker))return $fallback;
    if(!in_array($preferredProvider,['bstock','ondo','xstocks'],true))$preferredProvider='bstock';
    try{
        $q=$db->prepare("SELECT provider,symbol,underlying_name,token_name,LEFT(metadata_json,65536) AS metadata_json FROM rwa_tokens
          WHERE is_active=1 AND chain_id='56' AND underlying_ticker=? AND provider IN ('bstock','ondo','xstocks')
          ORDER BY CASE WHEN provider=? THEN 0 WHEN provider='bstock' THEN 1 WHEN provider='ondo' THEN 2 ELSE 3 END,symbol LIMIT 10");
        $q->execute([$ticker,$preferredProvider]);
        return chatAssetFromRows($ticker,$q->fetchAll(PDO::FETCH_ASSOC));
    }catch(Throwable $e){error_log('[rwa-chat-asset] saved catalogue metadata unavailable');return $fallback;}
}
