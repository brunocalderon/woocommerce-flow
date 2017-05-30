<?php 

function flow_get_endpoint() {
	return 'https://www.flow.cl/app/kpf/pago.php';
}

function flow_get_endpoint_test() {
	return 'http://flow.tuxpan.com/app/kpf/pago.php';
}

function flow_get_parameter($key) {
	global $_GET;
	return isset($_GET[$key])?$_GET[$key]:null;
}

function flow_get_post_parameter($key) {
	global $_POST;
	return isset($_POST[$key])?$_POST[$key]:null;
}

function flow_get_private_key_id($priv_key_data) {
	return openssl_get_privatekey($priv_key_data);
}

function flow_get_public_key_id() {
	$dir = dirname(__FILE__);
	$fp = fopen("$dir/flow.pubkey", "r");
	$pub_key = fread($fp, 8192);
	fclose($fp);
	return openssl_get_publickey($pub_key);
}

function flow_sign($priv_key, $data) {
	$priv_key_id = flow_get_private_key_id($priv_key);
	openssl_sign($data, $signature, $priv_key_id);
	return base64_encode($signature);
}

function flow_sign_validate($data) {
	$params = array();
	parse_str($data, $params);
	if (!isset($params['s'])) {
		throw new Exception('Invalid response (no signature)');
	}
	$signature = base64_decode($params['s']);

	$response = explode("&s=", $data, 2);
	$response = $response[0];

	$pub_key_id = flow_get_public_key_id();
	if (openssl_verify($response, $signature, $pub_key_id) != 1) {
		throw new Exception('Invalid signature from Flow');
	}
	return $params;
}

function flow_pack($privkey, $flow_comercio, $orden_compra, $monto, $modoPago, $concepto, $id_producto, 
		$flow_url_exito, $flow_url_fracaso, $flow_url_confirmacion,
		$flow_tipo_integracion, $flow_email) {
	
	$comercio = urlencode($flow_comercio);
	$orden_compra = urlencode($orden_compra);
	$id_producto = urlencode($id_producto);
	$monto = urlencode($monto);
	$modoPago = urlencode($modoPago);
	$email = urlencode($flow_email);
	
	$hConcepto = htmlentities($concepto);
	if (!$hConcepto) $hConcepto = htmlentities($concepto, ENT_COMPAT | ENT_HTML401, 'UTF-8');
	if (!$hConcepto) $hConcepto = htmlentities($concepto, ENT_COMPAT | ENT_HTML401, 'ISO-8859-1');
	if (!$hConcepto) $hConcepto = "Orden de Compra $orden_compra";
	
	$concepto = urlencode($hConcepto);
	
	$url_exito = urlencode($flow_url_exito);
	$url_fracaso = urlencode($flow_url_fracaso);
	$url_confirmacion = urlencode($flow_url_confirmacion);
	
	$p = "c=$comercio&oc=$orden_compra&mp=$modoPago&m=$monto&o=$concepto&id=$id_producto"
			."&ue=$url_exito&uf=$url_fracaso&uc=$url_confirmacion"
			."&ti=$flow_tipo_integracion&e=$email&v=wp_1_6";
	$signature = flow_sign($privkey, $p);
	return $p."&s=$signature";
}

function flow_build_response($priv_key, $data) {
	$q = http_build_query($data);
	$s = flow_sign($priv_key, $q);
	return $q."&s=".$s;
}

function flow_post($url, $data) {
	$ch = curl_init($url);

	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

	$response = curl_exec($ch);
	curl_close($ch);

	$data = flow_sign_validate($response);
	if (isset($data['error'])) throw new Exception($data['error']);
	return $data;
}

function flow_request($service, $params) {
	global $flow_url_pago;
	$data = flow_pack($params);

	return flow_post($flow_url_pago, $data);
}

function flow_sign_validate_post() {
	$data = file_get_contents('php://input');
	return flow_sign_validate($data);
}

function flow_aget($a, $key) {
	if (!isset($a[$key])) return null;
	return $a[$key];
}

?>