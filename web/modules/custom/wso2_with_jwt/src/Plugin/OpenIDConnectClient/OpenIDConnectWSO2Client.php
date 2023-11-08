<?php

namespace Drupal\wso2_with_jwt\Plugin\OpenIDConnectClient;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\openid_connect\Plugin\OpenIDConnectClientBase;
use Drupal\wso2_with_jwt\Exceptions\UnableToAuthorizeException;
use Drupal\wso2_with_jwt\Exceptions\UnableToRefreshTokenException;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Generic OpenID Connect client.
 *
 * Used primarily to login to Drupal sites powered by oauth2_server or PHP
 * sites powered by oauth2-server-php.
 *
 * @OpenIDConnectClient(
 *   id = "spid",
 *   label = @Translation("SPID")
 * )
 */
class OpenIDConnectWSO2Client extends OpenIDConnectClientBase
{
  /**
   * User private temp store.
   *
   * @var \Drupal\User\PrivateTempStoreFactory
   */
  public $privateTempStore;

  /**
   * OpenIDConnectWSO2Client constructor.
   *
   * @param array $configuration
   *   Configurations.
   * @param mixed $plugin_id
   *   Plugin Id.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   Request stack object.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   GuzzleHttp client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   Logger.
   * @param \Drupal\Component\Datetime\TimeInterface|null $datetime_time
   *   DataTime.
   * @param \Drupal\Core\PageCache\ResponsePolicy\KillSwitch|null $page_cache_kill_switch
   *   Page cache.
   * @param \Drupal\Core\Language\LanguageManagerInterface|null $language_manager
   *   Language manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    RequestStack $request_stack,
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    TimeInterface $datetime_time = NULL,
    KillSwitch $page_cache_kill_switch = NULL,
    LanguageManagerInterface $language_manager = NULL
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $request_stack,
      $http_client,
      $logger_factory,
      $datetime_time,
      $page_cache_kill_switch,
      $language_manager
    );

    $this->privateTempStore = \Drupal::getContainer()->get('user.private_tempstore');
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration()
  {
    return [
      'authorization_endpoint' => 'https://spid-dev.impleme.giottolabs.com/oauth2/authorize',
      'token_endpoint' => 'https://spid-dev.impleme.giottolabs.com/oauth2/token',
      'userinfo_endpoint' => 'https://spid-dev.impleme.giottolabs.com/oauth2/userinfo',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state)
  {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['authorization_endpoint'] = [
      '#title' => $this->t('Authorization endpoint'),
      '#type' => 'textfield',
      '#default_value' => $this->configuration['authorization_endpoint'],
    ];
    $form['token_endpoint'] = [
      '#title' => $this->t('Token endpoint'),
      '#type' => 'textfield',
      '#default_value' => $this->configuration['token_endpoint'],
    ];
    $form['userinfo_endpoint'] = [
      '#title' => $this->t('UserInfo endpoint'),
      '#type' => 'textfield',
      '#default_value' => $this->configuration['userinfo_endpoint'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getEndpoints()
  {
    return [
      'authorization' => $this->configuration['authorization_endpoint'],
      'token' => $this->configuration['token_endpoint'],
      'userinfo' => $this->configuration['userinfo_endpoint'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function retrieveTokens($authorization_code)
  {
    // Exchange `code` for access token and ID token.
    $redirect_uri = $this->getRedirectUrl()->toString();
    $endpoints = $this->getEndpoints();

    // Common request's options.
    $request_options = [
      'verify' => FALSE,
      'form_params' => [
        'client_id' => $this->configuration['client_id'],
        //'client_secret' => $this->configuration['client_secret'],
        // Lo scope è per WSO2.
        'redirect_uri' => $redirect_uri,
        'code' => $authorization_code,
        'scope' => 'openid',
        'grant_type' => 'authorization_code'
      ],
      'headers' => [
        'Accept' => 'application/json',
        'Content-Type' => 'application/x-www-form-urlencoded',
      ],
    ];

    $client = $this->httpClient;

    try {
      $response = $client->post($endpoints['token'], $request_options);
      $response_data = json_decode((string) $response->getBody());

      // Expected result.
      $tokens = [
        'id_token' => $response_data->id_token ?? NULL,
        'access_token' => $response_data->access_token ?? NULL,
      ];
      if ($response_data->expires_in) {
        $tokens['expire'] = $this->dateTime->getRequestTime() + $response_data->expires_in;
      }
      if ($response_data->refresh_token) {
        $tokens['refresh_token'] = $response_data->refresh_token;
      }
      return $tokens;
    } catch (\Exception $e) {
      $variables = [
        '@message' => 'Could not retrieve tokens',
        '@error_message' => $e->getMessage(),
      ];
      $this->loggerFactory->get('openid_connect_' . $this->pluginId)
        ->error('@message. Details: @error_message', $variables);

      throw new UnableToAuthorizeException('Could not retrieve tokens', $e->getCode(), $e);
    }
  }

  public function refreshToken($refreshToken)
  {
    // Exchange `code` for access token and ID token.
    $redirect_uri = $this->getRedirectUrl()->toString();
    $endpoints = $this->getEndpoints();

    // Common request's options.
    $request_options = [
      'verify' => FALSE,
      'form_params' => [
        'client_id' => $this->configuration['client_id'],
        'redirect_uri' => $redirect_uri,
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
      ],
      'headers' => [
        'Accept' => 'application/json',
        'Content-Type' => 'application/x-www-form-urlencoded',
      ],
    ];

    $client = $this->httpClient;

    try {
      $response = $client->post($endpoints['token'], $request_options);
      $response_data = json_decode((string) $response->getBody());

      // Expected result.
      $tokens = [
        'id_token' => $response_data->id_token ?? NULL,
        'access_token' => $response_data->access_token ?? NULL,
      ];
      if ($response_data->expires_in) {
        $tokens['expire'] = $this->dateTime->getRequestTime() + $response_data->expires_in;
      }
      if ($response_data->refresh_token) {
        $tokens['refresh_token'] = $response_data->refresh_token;
      }
      return $tokens;
    } catch (\Exception $e) {
      $variables = [
        '@message' => 'Could not refresh tokens',
        '@error_message' => $e->getMessage(),
      ];
      $this->loggerFactory->get('openid_connect_' . $this->pluginId)
        ->error('@message. Details: @error_message', $variables);
      
      throw new UnableToRefreshTokenException($e->getMessage(), $e->getCode(), $e);
    }
  }
}
