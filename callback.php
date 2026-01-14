<?php

error_reporting(E_ALL);
ini_set('display_errors', TRUE);
ini_set("log_errors", 1);
ini_set("error_log", 'error.log');

/**

def get_memberships(group_id, token):
    response = requests.get(f'{API_ROOT}groups/{group_id}', params={'token': token}).json()['response']['members']
    return response

def get_membership_id(group_id, user_id, token):
    memberships = get_memberships(group_id, token)
    for membership in memberships:
        if membership['user_id'] == user_id:
            return membership['id']
    return None

def remove_member(group_id, membership_id, token):
    response = requests.post(f'{API_ROOT}groups/{group_id}/members/{membership_id}/remove', params={'token': token})
    print('Attempted to kick user, got response:')
    print(response.text)
    return response.ok  # Return whether the request was successful


def delete_message(group_id, message_id, token):
    response = requests.delete(f'{API_ROOT}conversations/{group_id}/messages/{message_id}', params={'token': token})
    return response.ok


def kick_user(group_id, user_id, token):
    membership_id = get_membership_id(group_id, user_id, token)
    if membership_id:
        return remove_member(group_id, membership_id, token)
    return False

**/

function send($bot_id, $text) {
    $url = 'https://api.groupme.com/v3/bots/post';
    
    $message = array(
        'bot_id' => $bot_id,
        'text' => $text,
    );
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST,           1);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($message)); 
    curl_setopt($ch, CURLOPT_HTTPHEADER,     array('Content-Type: text/plain')); 
    
    $response = curl_exec($ch);
    error_log($response);
}

function get_memberships($group_id, $token) {
    $response = json_decode(file_get_contents("https://api.groupme.com/v3/groups/$group_id?token=$token"), true);
    
    return $response['response']['members'];
}

function get_user_info($group_id, $user_id, $token) {
    $memberships = get_memberships($group_id, $token);
    
    //error_log(json_encode($memberships));
    
    foreach ($memberships as $membership) {
        if ($membership['user_id'] == $user_id) {
            return $membership;
        }
    }
    return null;
}

function user_is_admin($group_id, $user_id, $token) {
    $user_roles = get_user_info($group_id, $user_id, $token)['roles'];
    return count(array_intersect(['admin', 'owner'], $user_roles)) != 0;
}

function get_membership_id($group_id, $user_id, $token) {
    $membership = get_user_info($group_id, $user_id, $token);
    return $membership === null ? null : $membership['id'];
}

function kick_user($group_id, $user_id, $token) {
    $membership_id = get_membership_id($group_id, $user_id, $token);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.groupme.com/v3/groups/$group_id/members/$membership_id/remove?token=$token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST,           1);
    $response = json_decode(curl_exec($ch), true);
    
    $success = $response['meta']['code'] < 400;
    
    return $success ? true : (implode("\n", $response['meta']['errors']));
}

function remove_message($group_id, $message_id, $token) {
    //response = requests.delete(f'{API_ROOT}conversations/{group_id}/messages/{message_id}', params={'token': token})
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.groupme.com/v3/conversations/$group_id/messages/$message_id?token=$token");
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
}


$request_body = json_decode(file_get_contents('php://input'), true);
$bot_id = $request_body['bot_id'];

file_put_contents('log', json_encode($request_body));

if ($request_body['sender_type'] == 'bot') {
   exit(0);
}

$group_id = $request_body['group_id'];
$filter_file = 'filters/' . $group_id;
if (!file_exists($filter_file)) { touch($filter_file); }

$message = $request_body['text'];

error_log($message);

if (str_starts_with($message, '/') && user_is_admin($group_id, $request_body['user_id'], $request_body['token'])) {
    $parts = explode(' ', substr($message,1), 2);
    
    if ($parts[0] == 'ping') {
        send($bot_id, "Hello! I'm alive");
    } else if ($parts[0] == 'add') {
        if (preg_match($parts[1], null) === false) {
            send($bot_id, 'Error: RegEx provided ' . $parts[1] . ' is invalid.');
        } else {
            if (!file_exists($filter_file)) { touch($filter_file); }
            file_put_contents($filter_file, file_get_contents($filter_file) . $parts[1] . "\n");
            send($bot_id, 'RegEx filter ' . $parts[1] . ' added successfully!');
        }
    } else if ($parts[0] == 'removeall') {
        unlink($filter_file);
        touch($filter_file);
        send($bot_id, "Removed all filters!");
    } else {
        send($bot_id, "Error: unrecognized command.");
    }
} else {
    $filters = explode("\n", file_get_contents($filter_file));
    array_pop($filters);
    foreach ($filters as $r) {
        if (preg_match($r, $message)) {
            error_log('Filter triggered: ' . $r . ' on message text "' . $message . '"');
            if (user_is_admin($group_id, $request_body['user_id'], $request_body['token']) == false) {
                
                remove_message($group_id, $request_body['id'], $request_body['token']);
                $k = kick_user($group_id, $request_body['user_id'], $request_body['token']);
                
                if ($k === true) {
                    send($bot_id, 'Kicked ' . $request_body['name'] . ' due to apparent spam post.');
                } else {
                    send($bot_id, "Unable to kick user: " . $k);
                }
                
                break;
            }
        }
    }
}

?>