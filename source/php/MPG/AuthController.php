<?php

namespace MPG;

class AuthController extends Controller {

    public static function ensureUserIsLogged() {

        if ( !isset($_SESSION['mpg']['user_is_logged']) ) {

            Routes::redirectTo('/login#');

        }

    }

    public function login() : ViewResponse {

        if ( isset($_POST['uri']) || isset($_POST['host']) ) {

            $requiredFields = $this->processFormData();
            
            if ( count($requiredFields) >= 1 ) {

                return new ViewResponse(200, 'login', [
                    'requiredFields' => $requiredFields
                ]);
                
            } else {

                $_SESSION['mpg']['user_is_logged'] = true;
                session_regenerate_id(true);
                // Rotate the CSRF token with the session: the new session
                // never carries a token that was valid before login
                // (login CSRF fixation).
                $_SESSION['mpg']['csrf_token'] = bin2hex(random_bytes(32));
                Routes::redirectTo('/');

            }

        } else {
            return new ViewResponse(200, 'login');
        }

    }

    /**
     * Wipes the per-user (Mongo) session state while preserving the CSRF
     * token: without this, the login view re-rendered in the same request
     * (failed login) would show an empty token and the form would be dead.
     */
    private function resetSessionState() : void {

        $csrfToken = $_SESSION['mpg']['csrf_token'] ?? null;

        $_SESSION['mpg'] = [];

        if ( $csrfToken !== null ) {
            $_SESSION['mpg']['csrf_token'] = $csrfToken;
        }

    }

    private function processFormData() : array {

        $requiredFields = [];
        $this->resetSessionState();

        if ( isset($_POST['uri']) ) {

            if ( preg_match(MongoDBHelper::URI_REGEX, $_POST['uri']) ) {

                try {
                    AppConfig::assertMongoUriAllowed($_POST['uri']);
                    $_SESSION['mpg']['mongodb_uri'] = $_POST['uri'];
                } catch (\InvalidArgumentException) {
                    $requiredFields[] = 'Host not allowed';
                }

            } else {
                $requiredFields[] = 'URI';
            }

        } elseif ( isset($_POST['host']) ) {

            if ( isset($_POST['user']) && !empty($_POST['user']) ) {
                $_SESSION['mpg']['mongodb_user'] = $_POST['user'];
            }
    
            if ( isset($_POST['password']) && !empty($_POST['password']) ) {
                $_SESSION['mpg']['mongodb_password'] = $_POST['password'];
            }
    
            if ( !empty($_POST['host']) ) {
                $_SESSION['mpg']['mongodb_host'] = $_POST['host'];

                try {
                    $host = AppConfig::extractHost($_POST['host']);
                    if ( !AppConfig::isHostAllowed($host) ) {
                        $requiredFields[] = 'Host not allowed';
                    }
                } catch (\InvalidArgumentException) {
                    $requiredFields[] = 'Host not allowed';
                }

            } else {
                $requiredFields[] = 'Host';
            }
    
            if ( isset($_POST['port']) && !empty($_POST['port']) ) {
                $_SESSION['mpg']['mongodb_port'] = $_POST['port'];
            }
            
            if ( isset($_POST['database']) && !empty($_POST['database']) ) {
                $_SESSION['mpg']['mongodb_database'] = $_POST['database'];
            }

        } else {
            $requiredFields[] = 'URI or Host';
        }

        return $requiredFields;

    }

    public function logout() {

        MongoDBHelper::clearClient();
        $this->resetSessionState();

        Routes::redirectTo('/login');

    }

}
