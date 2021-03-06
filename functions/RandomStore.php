<?php
function GetRandomStore($storeDataId)
{
    $gameData = \Base::instance()->get('GameData');
    $player = GetPlayer();
    $playerId = $player->id;
    $db = new RandomStore();
    $store = $db->findone(array(
        'dataId = ? AND playerId = ?',
        $storeDataId,
        $playerId
    ));
    $currentTime = time();
    $storeData = $gameData['randomStores'][$storeDataId];
    $refreshDuration = $storeData['refreshDuration'];
    $storeOutput = array();
    $randomedItems = array();
    if (!$store) {
        // Create new store for this user
        $itemsAmount = $storeData['itemsAmount'];
        for ($i = 0; $i < $itemsAmount; $i++) {
            $randomedItems[] = RandomRandomStoreItems($storeData);
        }
        $store = new RandomStore();
        $store->dataId = $storeDataId;
        $store->playerId = $playerId;
        $store->randomedItems = json_encode($randomedItems);
        $store->purchaseItems = '[]';
        $store->lastRefresh = $currentTime;
        $store->save();

        $storeOutput['randomedItems'] = $randomedItems;
        $storeOutput['purchaseItems'] = array();
    } else {
        if ($currentTime - $store->lastRefresh >= $refreshDuration) {
            // If its sales session is over, refresh
            $itemsAmount = $storeData['itemsAmount'];
            for ($i = 0; $i < $itemsAmount; $i++) {
                $randomedItems[] = RandomRandomStoreItems($storeData);
            }
            $store->randomedItems = json_encode($randomedItems);
            $store->purchaseItems = '[]';
            $store->lastRefresh = $currentTime;
            $store->save();

            $storeOutput['randomedItems'] = $randomedItems;
            $storeOutput['purchaseItems'] = array();
        }
        else
        {
            $storeOutput['randomedItems'] = json_decode($store->randomedItems, true);
            $storeOutput['purchaseItems'] = json_decode($store->purchaseItems, true);
        }
    }
    $storeOutput['dataId'] = $store->dataId;
    $storeOutput['lastRefresh'] = $store->lastRefresh;
    echo json_encode(array(
        'store' => $storeOutput,
        'endsIn' => ($store->lastRefresh + $refreshDuration) - $currentTime
    ));
}

function PurchaseRandomStoreItem($storeDataId, $index)
{
    $output = array('error' => '');
    $player = GetPlayer();
    $playerId = $player->id;
    $db = new RandomStore();
    $store = $db->findone(array(
        'dataId = ? AND playerId = ?',
        $storeDataId,
        $playerId
    ));
    if (!$store) {
        $output['error'] = 'ERROR_INVALID_STORE_ID';
    } else {
        $randomedItems = json_decode($store->randomedItems, true);
        $purchaseItems = json_decode($store->purchaseItems, true);
        if (in_array($index, $purchaseItems)) {
            $output['error'] = 'ERROR_ITEM_ALREADY_PURCHASED';
        } else {
            $rewardItems = array();
            $createItems = array();
            $updateItems = array();
            $updateCurrencies = array();
            // Get randomed item by defined index
            $randomedItem = $randomedItems[$index];
            $requireCurrencyId = $randomedItem['requireCurrencyId'];
            $requireCurrencyAmount = $randomedItem['requireCurrencyAmount'];
            $currency = GetCurrency($playerId, $requireCurrencyId);
            // Have enough currency?
            if ($requireCurrencyAmount > $currency->amount) {
                $output['error'] = 'ERROR_NOT_ENOUGH_CURRENCY';
            } else {
                // Add item to player's inventory
                $addItemsResult = AddItems($playerId, $randomedItem['id'], $randomedItem['amount']);
                if ($addItemsResult['success'])
                {
                    $rewardItems[] = CreateEmptyItem($index, $playerId, $randomedItem['id'], $randomedItem['amount']);
                    
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
                    
                    // Update purchase state
                    $purchaseItems[] = $index;
                    $store->purchaseItems = json_encode($purchaseItems);
                    $store->save();

                    // Decrease currency
                    $currency->amount -= $requireCurrencyAmount;
                    $currency->update();
                    $updateCurrencies[] = $currency;
                }
                $storeOutput = array();
                $storeOutput['dataId'] = $store->dataId;
                $storeOutput['lastRefresh'] = $store->lastRefresh;
                $storeOutput['randomedItems'] = $randomedItems;
                $storeOutput['purchaseItems'] = $purchaseItems;
                $output['rewardItems'] = ItemCursorsToArray($rewardItems);
                $output['createItems'] = ItemCursorsToArray($createItems);
                $output['updateItems'] = ItemCursorsToArray($updateItems);
                $output['updateCurrencies'] = CursorsToArray($updateCurrencies);
                $output['store'] = $storeOutput;
            }
        }
    }
    echo json_encode($output);
}

function RefreshRandomStore($storeDataId)
{
    $gameData = \Base::instance()->get('GameData');
    $player = GetPlayer();
    $playerId = $player->id;
    $db = new RandomStore();
    $store = $db->findone(array(
        'dataId = ? AND playerId = ?',
        $storeDataId,
        $playerId
    ));
    $currentTime = time();
    $storeData = $gameData['randomStores'][$storeDataId];
    $refreshDuration = $storeData['refreshDuration'];
    $refreshCurrencyId = $storeData['refreshCurrencyId'];
    $refreshCurrencyAmount = $storeData['refreshCurrencyAmount'];
    $currency = GetCurrency($playerId, $refreshCurrencyId);
    // Have enough currency?
    if ($refreshCurrencyAmount > $currency->amount) {
        echo json_encode(array('error' => 'ERROR_NOT_ENOUGH_CURRENCY'));
    } else {
        $randomedItems = array();
        if (!$store) {
            // Create new store for this user
            $itemsAmount = $storeData['itemsAmount'];
            for ($i = 0; $i < $itemsAmount; $i++) {
                $randomedItems[] = RandomRandomStoreItems($storeData);
            }
            $store = new RandomStore();
            $store->dataId = $storeDataId;
            $store->playerId = $playerId;
            $store->randomedItems = json_encode($randomedItems);
            $store->purchaseItems = '[]';
            $store->lastRefresh = $currentTime;
            $store->save();
            
            $storeOutput['randomedItems'] = $randomedItems;
            $storeOutput['purchaseItems'] = array();
        } else {
            // If its sales session is over, refresh
            $itemsAmount = $storeData['itemsAmount'];
            for ($i = 0; $i < $itemsAmount; $i++) {
                $randomedItems[] = RandomRandomStoreItems($storeData);
            }
            $store->randomedItems = json_encode($randomedItems);
            $store->purchaseItems = '[]';
            $store->lastRefresh = $currentTime;
            $store->save();

            $storeOutput['randomedItems'] = $randomedItems;
            $storeOutput['purchaseItems'] = array();
        }
        
        // Decrease currency
        $updateCurrencies = array();
        $currency->amount -= $refreshCurrencyAmount;
        $currency->update();
        $updateCurrencies[] = $currency;

        $storeOutput['dataId'] = $store->dataId;
        $storeOutput['lastRefresh'] = $store->lastRefresh;
        echo json_encode(array(
            'store' => $storeOutput,
            'endsIn' => ($store->lastRefresh + $refreshDuration) - $currentTime,
            'updateCurrencies' => CursorsToArray($updateCurrencies)
        ));
    }
}
?>