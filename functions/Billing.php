<?php
function VerifyAppstoreIAP($receipt, $is_sandbox) 
{
	//$sandbox should be TRUE if you want to test against itunes sandbox servers
	if ($is_sandbox)
		$verify_host = "ssl://sandbox.itunes.apple.com";
	else
		$verify_host = "ssl://buy.itunes.apple.com";
	
	$json = '{"receipt-data":"'.$receipt.'"}';
	//opening socket to itunes
	$fp = fsockopen ($verify_host, 443, $errno, $errstr, 30);
	if (!$fp) 
	{
		// HTTP ERROR
		return false;
	} 
	else
	{ 
		//iTune's request url is /verifyReceipt     
		$header = "POST /verifyReceipt HTTP/1.0\r\n";
		$header .= "Content-Type: application/x-www-form-urlencoded\r\n";
		$header .= "Content-Length: " . strlen($json) . "\r\n\r\n";
		fputs ($fp, $header . $json);
		$res = '';
		while (!feof($fp)) 
		{
			$step_res = fgets ($fp, 1024);
			$res = $res . $step_res;
		}
		fclose ($fp);
		//taking the JSON response
		$json_source = substr($res, stripos($res, "\r\n\r\n{") + 4);
		//decoding
		$app_store_response_map = json_decode($json_source);
		$app_store_response_status = $app_store_response_map->{'status'};
		if ($app_store_response_status == 0)//eithr OK or expired and needs to synch
		{
			//here are some fields from the json, btw.
			$json_receipt = $app_store_response_map->{'receipt'};
			$transaction_id = $json_receipt->{'transaction_id'};
			$original_transaction_id = $json_receipt->{'original_transaction_id'};
			$json_latest_receipt = $app_store_response_map->{'latest_receipt_info'};
			return true;
		}
		else
		{
			return false;
		}
	}
}
function VerfifyGooglePlayBilling($data, $signature, $public_key_base64) 
{
	$key =	"-----BEGIN PUBLIC KEY-----\n".
		chunk_split($public_key_base64, 64,"\n").
		'-----END PUBLIC KEY-----';   
	// Using PHP to create an RSA key
	$key = openssl_get_publickey($key);
	// $signature should be in binary format, but it comes as BASE64. 
	// So, I'll convert it.
	$signature = base64_decode($signature);   
	// Using PHP's native support to verify the signature
	$result = openssl_verify(
			$data,
			$signature,
			$key,
			OPENSSL_ALGO_SHA1);
	if (0 === $result) 
	{
		return false;
	}
	else if (1 !== $result)
	{
		return false;
	}
	else 
	{
		return true;
	}
}

function IOSBuyGoods($iapPackageDataId, $receipt)
{
    $gameData = \Base::instance()->get('GameData');
    $output = array('error' => '');
    $player = GetPlayer();
    $playerId = $player->id;
    $packageData = $gameData['iapPackages'][$iapPackageDataId];
    $decoded_receipt = json_decode(base64_decode($receipt), true);
    $productId = $decoded_receipt['productId'];
    // Use product id to find package in game data
    if (empty($packageData))
    {
        $output['error'] = 'ERROR_INVALID_IAP_PACKAGE_DATA';
    }
    else if ($packageData['appleAppstoreId'] != $productId)
    {
        $output['error'] = 'ERROR_INVALID_IAP_PACKAGE_DATA';
    }
    else if (!VerifyAppstoreIAP($receipt, \Base::instance()->get('appstore_is_sandbox')))
    {
        $buyGoodsOutput = BuyGoods($playerId, $gameData, $packageData);
        $output['rewardItems'] = $buyGoodsOutput['rewardItems'];
        $output['createItems'] = $buyGoodsOutput['createItems'];
        $output['updateItems'] = $buyGoodsOutput['updateItems'];
        $output['updateCurrencies'] = $buyGoodsOutput['updateCurrencies'];
        $output['rewardSoftCurrency'] = $buyGoodsOutput['rewardSoftCurrency'];
        $output['rewardHardCurrency'] = $buyGoodsOutput['rewardHardCurrency'];
    }
    echo json_encode($output);
}

function AndroidBuyGoods($iapPackageDataId, $data, $signature)
{
    $gameData = \Base::instance()->get('GameData');
    $output = array('error' => '');
    $player = GetPlayer();
    $playerId = $player->id;
    $packageData = $gameData['iapPackages'][$iapPackageDataId];
    $decoded_data = json_decode($data, true);
    $productId = $decoded_data['productId'];
    // Use product id to find package in game data
    if (empty($packageData))
    {
        $output['error'] = 'ERROR_INVALID_IAP_PACKAGE_DATA';
    }
    else if ($packageData['googlePlayId'] != $productId)
    {
        $output['error'] = 'ERROR_INVALID_IAP_PACKAGE_DATA';
    }
    else if (!VerfifyGooglePlayBilling($data, $signature, \Base::instance()->get('play_public_key')))
    {
        $buyGoodsOutput = BuyGoods($playerId, $gameData, $packageData);
        $output['rewardItems'] = $buyGoodsOutput['rewardItems'];
        $output['createItems'] = $buyGoodsOutput['createItems'];
        $output['updateItems'] = $buyGoodsOutput['updateItems'];
        $output['updateCurrencies'] = $buyGoodsOutput['updateCurrencies'];
        $output['rewardSoftCurrency'] = $buyGoodsOutput['rewardSoftCurrency'];
        $output['rewardHardCurrency'] = $buyGoodsOutput['rewardHardCurrency'];
    }
    echo json_encode($output);
}

function BuyGoods($playerId, $gameData, $packageData)
{
    $output = array();

    // Update currencies
    $updateCurrencies = array();
    // Soft currency
    $rewardSoftCurrency = $packageData['rewardSoftCurrency'];
    $softCurrency = GetCurrency($playerId, $gameData['currencies'][$gameData['softCurrencyId']]['id']);
    $softCurrency->amount += $rewardSoftCurrency;
    $softCurrency->update();
    $updateCurrencies[] = $softCurrency;
    // Hard currency
    $rewardHardCurrency = $packageData['rewardHardCurrency'];
    $hardCurrency = GetCurrency($playerId, $gameData['currencies'][$gameData['hardCurrencyId']]['id']);
    $hardCurrency->amount += $rewardHardCurrency;
    $hardCurrency->update();
    $updateCurrencies[] = $hardCurrency;
    // Add items
    $rewardItems = array();
    $createItems = array();
    $updateItems = array();
    $countRewardItems = count($packageData['rewardItems']);
    for ($i = 0; $i < $countRewardItems; ++$i)
    {
        $rewardItem = $packageData['rewardItems'][$i];
        if (empty($rewardItem) || empty($rewardItem['id'])) {
            continue;
        }
        
        $addItemsResult = AddItems($playerId, $rewardItem['id'], $rewardItem['amount']);
        if ($addItemsResult['success'])
        {
            $rewardItems[] = CreateEmptyItem($i, $playerId, $rewardItem['id'], $rewardItem['amount']);

            $resultCreateItems = $addItemsResult['createItems'];
            $resultUpdateItems = $addItemsResult['updateItems'];
            $countCreateItems = count($resultCreateItems);
            $countUpdateItems = count($resultUpdateItems);
            for ($j = 0; $j < $countCreateItems; ++$j)
            {
                $createItem = $resultCreateItems[$j];
                $createItem->save();
                HelperUnlockItem($playerId, $createItem->dataId);
                $createItems[] = $createItem;
            }
            for ($j = 0; $j < $countUpdateItems; ++$j)
            {
                $updateItem = $resultUpdateItems[$j];
                $updateItem->update();
                $updateItems[] = $updateItem;
            }
        }
    }
    $output['rewardItems'] = ItemCursorsToArray($rewardItems);
    $output['createItems'] = ItemCursorsToArray($createItems);
    $output['updateItems'] = ItemCursorsToArray($updateItems);
    $output['updateCurrencies'] = CursorsToArray($updateCurrencies);
    $output['rewardSoftCurrency'] = $rewardSoftCurrency;
    $output['rewardHardCurrency'] = $rewardHardCurrency;
    return $output;
}
?>