<?php

/**
 * ******************************** GLOBAL FUNCTIONS *********************************
 */
/**
 * Connects to the WS-API using the session $token passed as parameter
 *
 * @param string $token
 * @param string $user
 * @param string $password
 * @param int $role
 * @param string $team
 *
 * @throws APIException
 * @throws Exception
 * @return LinkcareSoapAPI
 */
function apiConnect($token, $user = null, $password = null, $role = null, $team = null, $reuseExistingSession = false, $language = null) {
    $session = null;
    $timezone = $GLOBALS['DEFAULT_TIMEZONE'];

    try {
        LinkcareSoapAPI::setEndpoint($GLOBALS["WS_LINK"]);
        if ($token) {
            LinkcareSoapAPI::session_join($token, $timezone);
        } else {
            LinkcareSoapAPI::session_init($user, $password, $timezone, $reuseExistingSession, $language);
        }

        $session = LinkcareSoapAPI::getInstance()->getSession();
        // Ensure to set the correct active ROLE and TEAM
        if ($team && $team != $session->getTeamCode() && $team != $session->getTeamId()) {
            LinkcareSoapAPI::getInstance()->session_set_team($team);
        }
        if ($role && $session->getRoleId() != $role) {
            LinkcareSoapAPI::getInstance()->session_role($role);
        }
        if ($language && $session->getLanguage() != $language) {
            LinkcareSoapAPI::getInstance()->session_set_language($language);
        }
        if ($timezone && $session->getTimezone() != $timezone) {
            LinkcareSoapAPI::getInstance()->session_set_timezone($timezone);
        }
    } catch (APIException $e) {
        throw $e;
    } catch (Exception $e) {
        throw $e;
    }

    return $session;
}
