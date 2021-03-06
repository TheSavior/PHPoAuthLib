<?php
namespace OAuth\OAuth2\Service;

use OAuth\Common\Consumer\Credentials;
use OAuth\Common\Exception\Exception;
use OAuth\Common\Service\AbstractService as BaseAbstractService;
use OAuth\Common\Storage\TokenStorageInterface;
use OAuth\Common\Http\Exception\TokenResponseException;
use OAuth\Common\Http\Client\ClientInterface;
use OAuth\Common\Http\Uri\UriInterface;
use OAuth\OAuth2\Service\Exception\InvalidScopeException;
use OAuth\OAuth2\Service\Exception\MissingRefreshTokenException;
use OAuth\Common\Token\TokenInterface;
use OAuth\Common\Token\Exception\ExpiredTokenException;

abstract class AbstractService extends BaseAbstractService implements ServiceInterface
{
    /** @var array */
    protected $scopes;

    /** @var \OAuth\Common\Http\Uri\UriInterface|null */
    protected $baseApiUri;

    /**
     * @param \OAuth\Common\Consumer\Credentials $credentials
     * @param \OAuth\Common\Http\Client\ClientInterface $httpClient
     * @param \OAuth\Common\Storage\TokenStorageInterface $storage
     * @param array $scopes
     * @param UriInterface|null $baseApiUri
     * @throws InvalidScopeException
     */
    public function __construct(Credentials $credentials, ClientInterface $httpClient, TokenStorageInterface $storage, $scopes = [], UriInterface $baseApiUri = null)
    {
        parent::__construct($credentials, $httpClient, $storage);
            
        foreach($scopes as $scope)
        {
            if( !$this->isValidScope($scope) ) {
                throw new InvalidScopeException('Scope ' . $scope . ' is not valid for service ' . get_class($this) );
            }
        }

        $this->scopes = $scopes;

        $this->baseApiUri = $baseApiUri;
    }

    /**
     * Returns the url to redirect to for authorization purposes.
     *
     * @param array $additionalParameters
     * @return string
     */
    public function getAuthorizationUri( array $additionalParameters = [] )
    {
        $parameters = array_merge($additionalParameters,
            [
                'type' => 'web_server',
                'client_id' => $this->credentials->getConsumerId(),
                'redirect_uri' => $this->credentials->getCallbackUrl(),
                'response_type' => 'code',
            ]
        );

        $parameters['scope'] = implode(' ', $this->scopes);

        // Build the url
        $url = clone $this->getAuthorizationEndpoint();
        foreach($parameters as $key => $val)
        {
            $url->addToQuery($key, $val);
        }

        return $url;
    }


    /**
     * Retrieves and stores the OAuth2 access token after a successful authorization.
     *
     * @param string $code The access code from the callback.
     * @return TokenInterface $token
     * @throws TokenResponseException
     */
    public function requestAccessToken($code)
    {
        $bodyParams =
        [
            'code' => $code,
            'client_id' => $this->credentials->getConsumerId(),
            'client_secret' => $this->credentials->getConsumerSecret(),
            'redirect_uri' => $this->credentials->getCallbackUrl(),
            'grant_type' => 'authorization_code',

        ];

        $responseBody = $this->httpClient->retrieveResponse($this->getAccessTokenEndpoint(), $bodyParams, $this->getExtraOAuthHeaders());
        $token = $this->parseAccessTokenResponse( $responseBody );
        $this->storage->storeAccessToken( $token );

        return $token;
    }

    /**
     * Sends an authenticated API request to the path provided.
     * If the path provided is not an absolute URI, the base API Uri (must be passed into constructor) will be used.
     * @param $path string|UriInterface
     * @param string $method HTTP method
     * @param array $body Request body if applicable (key/value pairs)
     * @param array $extraHeaders Extra headers if applicable. These will override service-specific any defaults.
     * @return string
     * @throws ExpiredTokenException
     * @throws Exception
     */
    public function request($path, $method = 'GET', array $body = [], array $extraHeaders = [])
    {
        $uri = $this->determineRequestUriFromPath($path, $this->baseApiUri);
        $token = $this->storage->retrieveAccessToken();

        if( ( $token->getEndOfLife() !== TokenInterface::EOL_NEVER_EXPIRES ) &&
            ( $token->getEndOfLife() !== TokenInterface::EOL_UNKNOWN ) &&
            ( time() > $token->getEndOfLife() ) ) {

            throw new ExpiredTokenException('Token expired on ' . date('m/d/Y', $token->getEndOfLife()) . ' at ' . date('h:i:s A', $token->getEndOfLife()) );
        }

        // add the token where it may be needed
        if( static::AUTHORIZATION_METHOD_HEADER_OAUTH === $this->getAuthorizationMethod() ) {
            $extraHeaders = array_merge( ['Authorization' => 'OAuth ' . $token->getAccessToken() ], $extraHeaders );
        } elseif( static::AUTHORIZATION_METHOD_QUERY_STRING === $this->getAuthorizationMethod() ) {
            $uri->addToQuery( 'access_token', $token->getAccessToken() );
        } elseif( static::AUTHORIZATION_METHOD_HEADER_BEARER === $this->getAuthorizationMethod() ) {
            $extraHeaders = array_merge( ['Authorization' => 'Bearer ' . $token->getAccessToken() ], $extraHeaders );
        }


        $extraHeaders = array_merge( $this->getExtraApiHeaders(), $extraHeaders );

        return $this->httpClient->retrieveResponse($uri, $body, $extraHeaders, $method);
    }
    
    /**
    * Accessor to the storage adapter to be able to retrieve tokens
    * 
    */
    public function getStorage() {
        return $this->storage;
    }

    /**
     * Refreshes an OAuth2 access token.
     *
     * @param \OAuth\Common\Token\TokenInterface $token
     * @return \OAuth\Common\Token\TokenInterface $token
     * @throws \OAuth\OAuth2\Service\Exception\MissingRefreshTokenException
     */
    public function refreshAccessToken(TokenInterface $token)
    {
        $refreshToken = $token->getRefreshToken();

        if ( empty( $refreshToken ) ) {
            throw new MissingRefreshTokenException();
        }

        $parameters =
        [
            'grant_type' => 'refresh_token',
            'type' => 'web_server',
            'client_id' => $this->credentials->getConsumerId(),
            'client_secret' => $this->credentials->getConsumerSecret(),
            'refresh_token' => $refreshToken,
        ];

        $responseBody = $this->httpClient->retrieveResponse($this->getAccessTokenEndpoint(), $parameters, $this->getExtraOAuthHeaders());
        $token = $this->parseAccessTokenResponse( $responseBody );
        $this->storage->storeAccessToken( $token );

        return $token;
    }

    /**
     * Return whether or not the passed scope value is valid.
     *
     * @param $scope
     * @return bool
     */
    public function isValidScope($scope)
    {
        $reflectionClass = new \ReflectionClass(get_class($this));
        return in_array( $scope, $reflectionClass->getConstants() );
    }

    /**
     * Return any additional headers always needed for this service implementation's OAuth calls.
     *
     * @return array
     */
    protected function getExtraOAuthHeaders()
    {
        return [];
    }

    /**
     * Return any additional headers always needed for this service implementation's API calls.
     *
     * @return array
     */
    protected function getExtraApiHeaders()
    {
        return [];
    }

    /**
     * Parses the access token response and returns a TokenInterface.
     *
     * @abstract
     * @return \OAuth\Common\Token\TokenInterface
     * @param string $responseBody
     */
    abstract protected function parseAccessTokenResponse($responseBody);

    /**
     * Returns a class constant from ServiceInterface defining the authorization method used for the API
     * Header is the sane default.
     *
     * @return int
     */
    protected function getAuthorizationMethod()
    {
        return static::AUTHORIZATION_METHOD_HEADER_OAUTH;
    }
}
