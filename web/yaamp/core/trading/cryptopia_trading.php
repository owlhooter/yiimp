<?php

function doCryptopiaCancelOrder($OrderID=false)
{
        if(!$OrderID) return;

        $params = array('CancelType'=>'Trade', 'OrderId'=>$OrderID);
        $res = cryptopia_api_user('CancelTrade', $params);
        if($res && $res->Success) {
                $db_order = getdbosql('db_orders', "market=:market AND uuid=:uuid", array(
                        ':market'=>'cryptopia', ':uuid'=>$OrderID
                ));
                if($db_order) $db_order->delete();
        }
}

function doCryptopiaTrading($quick=false)
{
        $exchange = 'cryptopia';
        $updatebalances = true;

        if (exchange_get($exchange, 'disabled')) return;

        $balances = cryptopia_api_user('GetBalance');
        if (!is_object($balances)) return;

        $savebalance = getdbosql('db_balances', "name='$exchange'");
        if (is_object($savebalance)) {
                $savebalance->balance = 0;
                $savebalance->onsell = 0;
                $savebalance->save();
        }

        if (is_array($balances->Data))
        foreach($balances->Data as $balance)
        {
                if ($balance->Symbol == 'MZC') {
                        if (is_object($savebalance)) {
                                $savebalance->balance = $balance->Available;
                                $savebalance->onsell = $balance->HeldForTrades;
                                $savebalance->save();
                        }
                        continue;
                }

                if ($updatebalances) {
                        // store available balance in market table
                        $coins = getdbolist('db_coins', "symbol=:symbol OR symbol2=:symbol",
                                array(':symbol'=>$balance->Symbol)
                        );
                        //debugLog("Updating balances");
                        if (empty($coins)) continue;
                        foreach ($coins as $coin) {
                                $market = getdbosql('db_markets', "coinid=:coinid AND name='$exchange'", array(':coinid'=>$coin->id));
                                if (!$market) continue;
                                //debugLog("Market for coin ".$coin->id." Balance = ".$balance->Available." held = ".$balance->HeldForTrades." message= ".$balance->StatusMessage);
                                $market->balance = $balance->Available;
                                $market->ontrade = $balance->HeldForTrades;
                                $market->message = $balance->StatusMessage;
                                if (property_exists($balance, 'Address'))
                                        if (!empty($balance->Address) && $market->deposit_address != $balance->Address) {
                                                debuglog("$exchange: {$coin->symbol} deposit address updated");
                                                $market->deposit_address = $balance->Address;
                                        }
                                $market->balancetime = time();
                                $market->save();
                        }
                }
        }

        if (!YAAMP_ALLOW_EXCHANGE) return;

        $flushall = rand(0, 8) == 0;
        if($quick) $flushall = false;

        $min_btc_trade = exchange_get($exchange, 'min_btc_trade', 0.00050000); // minimum allowed by the exchange
        $sell_ask_pct = 1.05;        // sell on ask price + 5%
        $cancel_ask_pct = 1.20;      // cancel order if our price is more than ask price + 20%

        // auto trade
        foreach ($balances->Data as $balance)
        {
                if ($balance->Total == 0) continue;
                if ($balance->Symbol == 'MZC') continue;
                debugLog('Symbol for trade = '.$balance->Symbol);
                $coin = getdbosql('db_coins', "symbol=:symbol AND dontsell=0", array(':symbol'=>$balance->Symbol));
                if(!$coin) continue;
                $symbol = $coin->symbol;
                if (!empty($coin->symbol2)) $symbol = $coin->symbol2;
				if ($symbol != 'BTC'){
					$market = getdbosql('db_markets', "coinid=:coinid AND name='cryptopia'", array(':coinid'=>$coin->id));
					if(!$market) continue;
					$market->balance = $balance->HeldForTrades;
					$market->message = $balance->StatusMessage;
				}
                $orders = NULL;
                if ($balance->HeldForTrades > 0) {
                        sleep(1);
                        if ($balance->Symbol == "BTC") {
                                $params = array('Market'=>"MZC/".$balance->Symbol);
								$ticker = cryptopia_api_query('GetMarket', '2580');
                        } else {
							$params = array('Market'=>$balance->Symbol."/BTC");
							$ticker = cryptopia_api_query('GetMarket', $market->marketid);
                        }
                        $orders = cryptopia_api_user('GetOpenOrders', $params);

                        sleep(1);
                        
                        if(!$ticker || !$ticker->Success || !$ticker->Data) continue;
                }

                // {"Success":true,"Error":null,"Data":[
                // {"OrderId":1067904,"TradePairId":2186,"Market":"CHC\/BTC","Type":"Sell","Rate":4.8e-6,"Amount":1500,"Total":0.0072,
                //  "Remaining":1500,"TimeStamp":"2016-02-01T16:18:44.839246"},
                if(is_object($orders) && $orders->Success && !empty($orders->Data))
                foreach($orders->Data as $order)
                {
                        $pairs = explode("/", $order->Market);
                        $pair = $order->Market;
                        if ($pairs[1] != 'BTC') continue;
                        //MAZA Orders
                        if ($balance->Symbol == 'BTC') {
                                //if($market->marketid == 0) {
                                       // $market->marketid = $order->TradePairId;
                                       // $market->save();
                               // }
                                $sellprice = bitcoinvaluetoa($order->Rate);
                                $ask = bitcoinvaluetoa($ticker->Data->AskPrice);
                                // cancel orders not on the wanted sell range f 20%
                                if($ask < $sellprice-($sellprice*.2) || $flushall)
                                        {
                                        debuglog("cryptopia: cancel order $pair at $ask, ask price is now $sellprice");
                                        sleep(1);
                                        doCryptopiaCancelOrder($order->OrderId);
                                }
                                // store existing orders
                                else
                                {
                                        $db_order = getdbosql('db_orders', "market=:market AND uuid=:uuid", array(
                                                ':market'=>'cryptopia', ':uuid'=>$order->OrderId
                                        ));
                                        if($db_order) continue;

                                        // debuglog("cryptopia: store order of {$order->Amount} {$symbol} at $sellprice BTC");
                                        $db_order = new db_orders;
                                        $db_order->market = 'cryptopia';
                                        $db_order->coinid = $coin->id;
                                        $db_order->amount = $order->Amount;
                                        $db_order->price = $sellprice;
                                        $db_order->ask = $ticker->Data->AskPrice;
                                        $db_order->bid = $ticker->Data->BidPrice;
                                        $db_order->uuid = $order->OrderId;
                                        $db_order->created = time(); // $order->TimeStamp 2016-03-07T20:04:05.3947572"
                                        $db_order->save();
                                }
                                continue;
                        }
                        // ignore buy orders
                        if(stripos($order->Type, 'Sell') === false) continue;

                        if($market->marketid == 0) {
                                $market->marketid = $order->TradePairId;
                                $market->save();
                        }

                        $ask = bitcoinvaluetoa($ticker->Data->AskPrice);
                        $sellprice = bitcoinvaluetoa($order->Rate);

                        // cancel orders not on the wanted ask range
                        if($sellprice > $ask*$cancel_ask_pct || $flushall)
                        {
                                debuglog("cryptopia: cancel order $pair at $sellprice, ask price is now $ask");
                                sleep(1);
                                doCryptopiaCancelOrder($order->OrderId);
                        }
                        // store existing orders
                        else
                        {
                                $db_order = getdbosql('db_orders', "market=:market AND uuid=:uuid", array(
                                        ':market'=>'cryptopia', ':uuid'=>$order->OrderId
                                ));
                                if($db_order) continue;

                                // debuglog("cryptopia: store order of {$order->Amount} {$symbol} at $sellprice BTC");
                                $db_order = new db_orders;
                                $db_order->market = 'cryptopia';
                                $db_order->coinid = $coin->id;
                                $db_order->amount = $order->Amount;
                                $db_order->price = $sellprice;
                                $db_order->ask = $ticker->Data->AskPrice;
                                $db_order->bid = $ticker->Data->BidPrice;
                                $db_order->uuid = $order->OrderId;
                                $db_order->created = time(); // $order->TimeStamp 2016-03-07T20:04:05.3947572"
                                $db_order->save();
                        }
                }

                // drop obsolete orders
                $list = getdbolist('db_orders', "coinid={$coin->id} AND market='cryptopia'");
                foreach($list as $db_order)
                {
                        $found = false;
                        if(is_object($orders) && $orders->Success)
                        foreach($orders->Data as $order) {
                                //if(stripos($order->Type, 'Sell') === false) continue; //Allow to check buy orders for MAZA
                                if($order->OrderId == $db_order->uuid) {
                                        $found = true;
                                        break;
                                }
                        }

                        if(!$found) {
                                // debuglog("cryptopia: delete db order {$db_order->amount} {$coin->symbol} at {$db_order->price} BTC");
                                $db_order->delete();
                        }
                }

                if($coin->dontsell) continue;

                $market->lasttraded = time();
                $market->save();

                // new orders
                $amount = floatval($balance->Available);
                debugLog("Balance = ".$balance->Available." ammount = ".$amount." coin = ".$coin->symbol." amount*coinprice = ".$amount*$coin->price." mintrade = ".$min_btc_trade);
                if(!$amount) continue;
                
                if ($balance->Symbol == 'BTC') {
                        if($amount < $min_btc_trade) continue;
						$data = cryptopia_api_query('GetMarketOrders', "2580/5");
                } else {
                        if($amount*$coin->price < $min_btc_trade || !$market->marketid) continue;
						$data = cryptopia_api_query('GetMarketOrders', $market->marketid."/5");
                }
                
                sleep(1);
                
                if(!$data || !$data->Success || !$data->Data) continue;
                $cont = false;
                if($coin->sellonbid)
                for($i = 0; $i < 5 && $amount >= 0; $i++)
                {
                        
                        //Buy MAZA
                        if ($balance->Symbol == 'BTC') {
                                if(!isset($data->Data->Sell[$i])) break;

                                $nextbuy = $data->Data->Sell[$i];
                                $sellprice = bitcoinvaluetoa($nextbuy->Price);
                                if(($amount/$sellprice)*1.1 < $nextbuy->Volume) break;


                                $sellamount = min($amount/$sellprice-10, $nextbuy->Volume);

                                if($sellamount*$sellprice < $min_btc_trade) {
                                        
                                        continue;
                                }
                                debuglog("cryptopia 1: selling $sellamount $symbol at $sellprice");
                                sleep(1);
                                $params = array('TradePairId'=>'2580', 'Type'=>'Buy', 'Rate'=>$sellprice, 'Amount'=>$sellamount);
                                $res = cryptopia_api_user('SubmitTrade', $params);
                                if(!$res || !$res->Success || !$res->Data) {
                                        debuglog("cryptopia SubmitTrade err: ".json_encode($res));
                                        break;
                                }
                        }
                        else {
                                //Sell others
                                if(!isset($data->Data->Buy[$i])) break;

                                $nextbuy = $data->Data->Buy[$i];
                                if($amount*1.1 < $nextbuy->Volume) break;

                                $sellprice = bitcoinvaluetoa($nextbuy->Price);
                                $sellamount = min($amount, $nextbuy->Volume);

                                if($sellamount*$sellprice < $min_btc_trade) {
                                        continue;
                                }
                                debuglog("cryptopia 2: selling $sellamount $symbol at $sellprice");
                                sleep(1);
                                $params = array('TradePairId'=>$market->marketid, 'Type'=>'Sell', 'Rate'=>$sellprice, 'Amount'=>$sellamount);
                                $res = cryptopia_api_user('SubmitTrade', $params);
                                if(!$res || !$res->Success || !$res->Data) {
                                        debuglog("cryptopia SubmitTrade err: ".json_encode($res));
                                        break;
                                }
                        }
                        $amount -= $sellamount;
                }

                if($amount <= 0) continue;

                sleep(1);
				if ($balance->Symbol == 'BTC')
					$ticker = cryptopia_api_query('GetMarket', '2580');
				else
					$ticker = cryptopia_api_query('GetMarket', $market->marketid);
                if(!$ticker || !$ticker->Success || !$ticker->Data) continue;

                if($coin->sellonbid)
                        $sellprice = bitcoinvaluetoa($ticker->Data->AskPrice);
                else
                        $sellprice = bitcoinvaluetoa($ticker->Data->AskPrice * $sell_ask_pct); // lowest ask price +5%

                $cont=false;
                if ($balance->Symbol == 'BTC') {
                        if($amount < $min_btc_trade) $cont=true;

                } else {
                        if($amount*$coin->price_btc < $min_btc_trade || !$market->marketid) $cont=true;
                }
                if ($cont == "true") continue;
                debuglog("cryptopia 3: selling $amount $symbol at $sellprice");

                sleep(1);
                //Buy Maza sell others
                if ($balance->Symbol == 'BTC') {
                        $params = array('TradePairId'=>'2580', 'Type'=>'Buy', 'Rate'=>$sellprice, 'Amount'=>$amount/$sellprice-10);
                }
                else {
                $params = array('TradePairId'=>$market->marketid, 'Type'=>'Sell', 'Rate'=>$sellprice, 'Amount'=>$amount);
                }
                $res = cryptopia_api_user('SubmitTrade', $params);
                if(!$res || !$res->Success || !$res->Data) {
                        debuglog("cryptopia SubmitTrade err: ".json_encode($res));
                        continue;
                }

                $db_order = new db_orders;
                $db_order->market = 'cryptopia';
                $db_order->coinid = $coin->id;
                $db_order->amount = $amount;
                $db_order->price = $sellprice;
                $db_order->ask = $ticker->Data->AskPrice;
                $db_order->bid = $ticker->Data->BidPrice;
                $db_order->uuid = $res->Data->OrderId;
                $db_order->created = time();
                $db_order->save();
        }

        $withdraw_min = exchange_get($exchange, 'withdraw_min_btc', EXCH_AUTO_WITHDRAW);
        $withdraw_fee = exchange_get($exchange, 'withdraw_fee_btc', 0.002);

        // auto withdraw
        if(is_object($savebalance))
        if(floatval($withdraw_min) > 0 && $savebalance->balance >= ($withdraw_min + $withdraw_fee))
        {
                // $btcaddr = exchange_get($exchange, 'withdraw_btc_address', YAAMP_BTCADDRESS);
                $btcaddr = YAAMP_BTCADDRESS;
                $amount = $savebalance->balance - $withdraw_fee;
                debuglog("cryptopia: withdraw $amount MAZA to $btcaddr");

                sleep(1);
                $params = array("Currency"=>"MZC", "Amount"=>$amount, "Address"=>$btcaddr);
                $res = cryptopia_api_user('SubmitWithdraw', $params);
                if(is_object($res) && $res->Success)
                {
                        $withdraw = new db_withdraws;
                        $withdraw->market = 'cryptopia';
                        $withdraw->address = $btcaddr;
                        $withdraw->amount = $amount;
                        $withdraw->time = time();
                        $withdraw->uuid = $res->Data;
                        $withdraw->save();

                        $savebalance->balance = 0;
                        $savebalance->save();
                } else {
                        debuglog("cryptopia withdraw MAZA error: ".json_encode($res));
                }
        }

}
