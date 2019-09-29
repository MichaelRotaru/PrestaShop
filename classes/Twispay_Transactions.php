<?php
/**
 * Twispay Helpers
 *
 * Logs messages and transactions.
 *
 * @author   Twistpay
 * @version  1.0.1
 */

/* Security class check */
if (! class_exists('Twispay_Transactions')) :
    /**
     * Class that implements custom transaction table and the assigned operations
     */
    class Twispay_Transactions
    {
      public static function createTransactionsTable()
      {
          $sql = "
          CREATE TABLE IF NOT EXISTS `"._DB_PREFIX_."twispay_transactions` (
              `id_transaction` int(10) NOT NULL AUTO_INCREMENT,
              `status` varchar(50) NOT NULL,
              `id_cart` int(10) NOT NULL,
              `identifier` varchar(50) NOT NULL,
              `customerId` int(10) NOT NULL,
              `orderId` int(10) NOT NULL,
              `cardId` int(10) NOT NULL,
              `transactionId` int(10) NOT NULL,
              `transactionKind` varchar(50) NOT NULL,
              `amount` float NOT NULL,
              `currency` varchar(8) NOT NULL,
              `date` DATETIME NOT NULL,
              PRIMARY KEY (`id_transaction`)
          ) ENGINE="._MYSQL_ENGINE_." DEFAULT CHARSET=utf8;";
          return Db::getInstance()->execute($sql);
      }

      public static function insertTransaction($data)
      {
          $columns = array(
              'status',
              'id_cart',
              'identifier',
              'customerId',
              'orderId',
              'cardId',
              'transactionId',
              'transactionKind',
              'amount',
              'currency',
              'timestamp',
          );

          foreach (array_keys($data) as $key) {
              if (!in_array($key, $columns)) {
                  unset($data[$key]);
              } else {
                  $data[$key] = pSQL($data[$key]);
              }
          }
          if (!empty($data['timestamp'])) {
              $data['date'] = pSQL(date('Y-m-d H:i:s', $data['timestamp']));
              unset($data['timestamp']);
          }
          if (!empty($data['identifier'])) {
              $data['identifier'] = (int)str_replace('_', '', $data['identifier']);
          }
          Db::getInstance()->insert('twispay_transactions', $data);
      }

      public static function getTransactions($page, $selected_pagination)
      {
          if ((int)$page <= 0) {
              $page = 1;
          }
          $limit = ((int)$page-1)*$selected_pagination;
          return Db::getInstance()->executeS('SELECT tt.*, o.`reference`
          as `order_reference`, CONCAT(tt.`amount`, " ", tt.`currency`)
          as `amount_formatted`, CONCAT(c.`firstname`," ", c.`lastname`)
          as `customer_name`  FROM `'._DB_PREFIX_.'twispay_transactions` tt
          LEFT JOIN `'._DB_PREFIX_.'orders` o LEFT JOIN `'._DB_PREFIX_.'customer` c
          ON (c.`id_customer` = o.`id_customer`) ON (o.`id_cart` = tt.`id_cart`)
          ORDER BY `id_transaction` DESC LIMIT '. (int)$limit .', '.(int)$selected_pagination);
      }

      public static function getTransactionByCartId($id_cart){
          // TODO Twispay_Logger::log('SELECT * FROM `'._DB_PREFIX_.'twispay_transactions` WHERE `id_card`='.(int)$id_cart);
          $result = Db::getInstance()->executeS('SELECT * FROM `'._DB_PREFIX_.'twispay_transactions` WHERE `id_cart`='.(int)$id_cart);
          return $result?$result[0]:FALSE;
      }

      public static function checkTransaction($id)
      {
          // TODO $db_trans_id = $this->db->escape($id);
          $result = Db::getInstance()->executeS('SELECT * FROM `'._DB_PREFIX_.'twispay_transactions` WHERE `transactionId`='.(int)$id);
          if ($result) {
              return TRUE;
          } else {
              return FALSE;
          }
      }

      public static function getTransactionsNumber()
      {
          return (int)Db::getInstance()->getValue('SELECT COUNT(*) FROM `'._DB_PREFIX_.'twispay_transactions`');
      }

      /**
       * Function that call the refund operation via Twispay API and update the local order based on the response.
       *
       * @param array transaction - Twispay transaction info
       * @param array keys - Api keys
       *
       * @return array([key => value,]) - string 'status'         - API Message
       *                                  string 'rawdata'        - Unprocessed response
       *                                  string 'id_transaction' - The twispay id of the refunded transaction
       *                                  string 'id_cart'        - The opencart id of the canceled order
       *                                  boolean 'refunded'      - Operation success indicator
       *
       */
      public static function refundTransaction($transaction, $keys)
      {
        /** Check if the api key is defined */
          $postData = 'amount=' . $transaction['amount'] . '&' . 'message=' . 'Refund for order ' . $transaction['orderId'];
          if ($keys['liveMode']) {
              $url = 'https://api.twispay.com/transaction/' . $transaction['transactionId'];
          } else {
              $url = 'https://api-stage.twispay.com/transaction/' . $transaction['transactionId'];
          }

          /** Create a new cURL session. */
          $ch = curl_init();
          curl_setopt($ch, CURLOPT_URL, $url);
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
          curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Authorization: ' . $keys['privateKey']]);
          curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
          curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
          $response = curl_exec($ch);
          curl_close($ch);
          $json = json_decode($response);

          /** Check if curl/decode fails */
          if (!isset($json)) {
              $json = new stdClass();
              $json->message = $this->l('json_decode_error');
              Twispay_Logger::api_log($this->l('json_decode_error'));
          }

          if ($json->message == 'Success') {
              $data = array(
                 'status'          => Twispay_Status_Updater::$RESULT_STATUSES['REFUND_OK'],
                 'rawdata'         => $json,
                 'id_transaction'  => $transaction['transactionId'],
                 'id_cart'         => $transaction['id_cart'],
                 'refunded'        => 1,
             );
          } else {
              $data = array(
                 'status'          => isset($json->error)?$json->error[0]->message:$json->message,
                 'rawdata'         => $json,
                 'id_transaction'  => $transaction['transactionId'],
                 'id_cart'         => $transaction['id_cart'],
                 'refunded'        => 0,
             );
          }
          return $data;

      }
    }
endif; /* End if class_exists. */