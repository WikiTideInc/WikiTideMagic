<?php

use MediaWiki\Cache\Hook\MessageCache__getHook;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterShouldFilterActionHook;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Hook\BeforeInitializeHook;
use MediaWiki\Hook\ContributionsToolLinksHook;
use MediaWiki\Hook\GetLocalURL__InternalHook;
use MediaWiki\Hook\SiteNoticeAfterHook;
use MediaWiki\Hook\SkinAddFooterLinksHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\Hook\TitleReadWhitelistHook;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\User\UserOptionsManager;
use WikiForge\CreateWiki\Hooks\CreateWikiDeletionHook;
use WikiForge\CreateWiki\Hooks\CreateWikiReadPersistentModelHook;
use WikiForge\CreateWiki\Hooks\CreateWikiRenameHook;
use WikiForge\CreateWiki\Hooks\CreateWikiStatePrivateHook;
use WikiForge\CreateWiki\Hooks\CreateWikiTablesHook;
use WikiForge\CreateWiki\Hooks\CreateWikiWritePersistentModelHook;
use WikiForge\ManageWiki\Helpers\ManageWikiSettings;
use Wikimedia\IPUtils;

class WikiTideMagicHooks implements
	AbuseFilterShouldFilterActionHook,
	BeforeInitializeHook,
	ContributionsToolLinksHook,
	CreateWikiDeletionHook,
	CreateWikiReadPersistentModelHook,
	CreateWikiRenameHook,
	CreateWikiStatePrivateHook,
	CreateWikiTablesHook,
	CreateWikiWritePersistentModelHook,
	GetLocalURL__InternalHook,
	GetPreferencesHook,
	MessageCache__getHook,
	SiteNoticeAfterHook,
	SkinAddFooterLinksHook,
	TitleReadWhitelistHook
{

	/** @var ServiceOptions */
	private $options;

	/** @var UserOptionsManager */
	private $userOptionsManager;

	/**
	 * @param ServiceOptions $options
	 * @param UserOptionsManager $userOptionsManager
	 */
	public function __construct(
		ServiceOptions $options,
		UserOptionsManager $userOptionsManager
	) {
		$this->options = $options;
		$this->userOptionsManager = $userOptionsManager;
	}

	/**
	 * @param Config $mainConfig
	 * @param UserOptionsManager $userOptionsManager
	 *
	 * @return self
	 */
	public static function factory(
		Config $mainConfig,
		UserOptionsManager $userOptionsManager
	): self {
		return new self(
			new ServiceOptions(
				[
					'ArticlePath',
					'CreateWikiCacheDirectory',
					'CreateWikiGlobalWiki',
					'EchoSharedTrackingDB',
					'JobTypeConf',
					'LanguageCode',
					'LocalDatabases',
					'ManageWikiSettings',
					'Script',
					'WikiTideMagicMemcachedServer',
				],
				$mainConfig
			),
			$userOptionsManager
		);
	}

	/**
	 * Avoid filtering automatic account creation
	 *
	 * @param VariableHolder $vars
	 * @param Title $title
	 * @param User $user
	 * @param array &$skipReasons
	 * @return bool|void
	 */
	public function onAbuseFilterShouldFilterAction(
		VariableHolder $vars,
		Title $title,
		User $user,
		array &$skipReasons
	) {
		if ( defined( 'MW_PHPUNIT_TEST' ) ) {
			return;
		}

		$varManager = AbuseFilterServices::getVariablesManager();

		$action = $varManager->getVar( $vars, 'action', 1 )->toString();
		if ( $action === 'autocreateaccount' ) {
			$skipReasons[] = 'Blocking automatic account creation is not allowed';

			return false;
		}
	}

	public function onCreateWikiDeletion( $cwdb, $wiki ): void {
		global $wmgSwiftPassword;

		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancerFactory()
			->getMainLB( $this->options->get( 'EchoSharedTrackingDB' ) )
			->getMaintenanceConnectionRef( DB_PRIMARY, [], $this->options->get( 'EchoSharedTrackingDB' ) );

		$dbw->delete( 'echo_unread_wikis', [ 'euw_wiki' => $wiki ] );

		foreach ( $this->options->get( 'LocalDatabases' ) as $db ) {
			$manageWikiSettings = new ManageWikiSettings( $db );

			foreach ( $this->options->get( 'ManageWikiSettings' ) as $var => $setConfig ) {
				if (
					$setConfig['type'] === 'database' &&
					$manageWikiSettings->list( $var ) === $wiki
				) {
					$manageWikiSettings->remove( $var );
					$manageWikiSettings->commit();
				}
			}
		}

		$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

		// Get a list of containers to delete for the wiki
		$containers = explode( "\n",
			trim( Shell::command(
				'swift', 'list',
				'--prefix', 'wikitide-' . $wiki . '-',
				'-A', 'https://swift-lb.wikitide.net/auth/v1.0',
				'-U', 'mw:media',
				'-K', $wmgSwiftPassword
			)->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute()->getStdout()
			)
		);

		foreach ( $containers as $container ) {
			// Just an extra precaution to ensure we don't select the wrong containers
			if ( !str_contains( $container, $wiki . '-' ) ) {
				continue;
			}

			// Delete the container
			Shell::command(
				'swift', 'delete',
				$container,
				'-A', 'https://swift-lb.wikitide.net/auth/v1.0',
				'-U', 'mw:media',
				'-K', $wmgSwiftPassword
			)->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute();
		}

		$this->removeRedisKey( "*{$wiki}*" );
		$this->removeMemcachedKey( ".*{$wiki}.*" );
	}

	public function onCreateWikiRename( $cwdb, $old, $new ): void {
		global $wmgSwiftPassword;

		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancerFactory()
			->getMainLB( $this->options->get( 'EchoSharedTrackingDB' ) )
			->getMaintenanceConnectionRef( DB_PRIMARY, [], $this->options->get( 'EchoSharedTrackingDB' ) );

		$dbw->update( 'echo_unread_wikis', [ 'euw_wiki' => $new ], [ 'euw_wiki' => $old ] );

		foreach ( $this->options->get( 'LocalDatabases' ) as $db ) {
			$manageWikiSettings = new ManageWikiSettings( $db );

			foreach ( $this->options->get( 'ManageWikiSettings' ) as $var => $setConfig ) {
				if (
					$setConfig['type'] === 'database' &&
					$manageWikiSettings->list( $var ) === $old
				) {
					$manageWikiSettings->modify( [ $var => $new ] );
					$manageWikiSettings->commit();
				}
			}
		}

		$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

		// Get a list of containers to download, and later upload for the wiki
		$containers = explode( "\n",
			trim( Shell::command(
				'swift', 'list',
				'--prefix', 'wikitide-' . $old . '-',
				'-A', 'https://swift-lb.wikitide.net/auth/v1.0',
				'-U', 'mw:media',
				'-K', $wmgSwiftPassword
			)->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute()->getStdout()
			)
		);

		foreach ( $containers as $container ) {
			// Just an extra precaution to ensure we don't select the wrong containers
			if ( !str_contains( $container, $old . '-' ) ) {
				continue;
			}

			// Get a list of all files in the container to ensure everything is present in new container later.
			$oldContainerList = Shell::command(
				'swift', 'list',
				$container,
				'-A', 'https://swift-lb.wikitide.net/auth/v1.0',
				'-U', 'mw:media',
				'-K', $wmgSwiftPassword
			)->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute()->getStdout();

			// Download the container
			Shell::command(
				'swift', 'download',
				$container,
				'-D', wfTempDir() . '/' . $container,
				'-A', 'https://swift-lb.wikitide.net/auth/v1.0',
				'-U', 'mw:media',
				'-K', $wmgSwiftPassword
			)->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute();

			$newContainer = str_replace( $old, $new, $container );

			// Upload to new container
			// We have to use exec here, as Shell::command does not work for this
			// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions
			exec( escapeshellcmd(
				implode( ' ', [
					'swift', 'upload',
					$newContainer,
					wfTempDir() . '/' . $container,
					'--object-name', '""',
					'-A', 'https://swift-lb.wikitide.net/auth/v1.0',
					'-U', 'mw:media',
					'-K', $wmgSwiftPassword
				] )
			) );

			wfDebugLog( 'WikiTideMagic', "Container '$newContainer' created." );

			$newContainerList = Shell::command(
				'swift', 'list',
				$newContainer,
				'-A', 'https://swift-lb.wikitide.net/auth/v1.0',
				'-U', 'mw:media',
				'-K', $wmgSwiftPassword
			)->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute()->getStdout();

			if ( $newContainerList === $oldContainerList ) {
				// Everything has been correctly copied over
				// wipe files from the temp directory and delete old container

				// Delete the container
				Shell::command(
					'swift', 'delete',
					$container,
					'-A', 'https://swift-lb.wikitide.net/auth/v1.0',
					'-U', 'mw:media',
					'-K', $wmgSwiftPassword
				)->limits( $limits )
					->restrict( Shell::RESTRICT_NONE )
					->execute();

				wfDebugLog( 'WikiTideMagic', "Container '$container' deleted." );

				// Wipe from the temp directory
				Shell::command( '/bin/rm', '-rf', wfTempDir() . '/' . $container )
					->limits( $limits )
					->restrict( Shell::RESTRICT_NONE )
					->execute();
			} else {
				/**
				 * We need to log this, as otherwise all files may not have been succesfully
				 * moved to the new container, and they still exist locally. We should know that.
				 */
				wfDebugLog( 'WikiTideMagic', "The rename of wiki $old to $new may not have been successful. Files still exist locally in {wfTempDir()} and the Swift containers for the old wiki still exist." );
			}
		}

		$scriptOptions = [ 'wrapper' => MW_INSTALL_PATH . '/maintenance/run.php' ];

		Shell::makeScriptCommand(
			MW_INSTALL_PATH . '/extensions/CreateWiki/maintenance/setContainersAccess.php',
			[
				'--wiki', $new
			],
			$scriptOptions
		)->limits( $limits )->execute();

		$this->removeRedisKey( "*{$old}*" );
		$this->removeMemcachedKey( ".*{$old}.*" );
	}

	public function onCreateWikiStatePrivate( $dbname ): void {
		$localRepo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
		$sitemaps = $localRepo->getBackend()->getTopFileList( [
			'dir' => $localRepo->getZonePath( 'public' ) . '/sitemaps',
			'adviseStat' => false,
		] );

		foreach ( $sitemaps as $sitemap ) {
			$status = $localRepo->getBackend()->quickDelete( [
				'src' => $localRepo->getZonePath( 'public' ) . '/sitemaps/' . $sitemap,
			] );

			if ( !$status->isOK() ) {
				/**
				 * We need to log this, as otherwise the sitemaps may
				 * not be being deleted for private wikis. We should know that.
				 */
				$statusMessage = Status::wrap( $status )->getWikitext();
				wfDebugLog( 'WikiTideMagic', "Sitemap \"{$sitemap}\" failed to delete: {$statusMessage}" );
			}
		}

		$localRepo->getBackend()->clean( [ 'dir' => $localRepo->getZonePath( 'public' ) . '/sitemaps' ] );
	}

	public function onCreateWikiTables( &$cTables ): void {
		$tables['localnames'] = 'ln_wiki';
		$tables['localuser'] = 'lu_wiki';
	}

	public function onCreateWikiReadPersistentModel( &$pipeline ): void {
		$backend = MediaWikiServices::getInstance()->getFileBackendGroup()->get( 'wikitide-swift' );
		if ( $backend->fileExists( [ 'src' => $backend->getContainerStoragePath( 'createwiki-persistent-model' ) . '/requestmodel.phpml' ] ) ) {
			$pipeline = unserialize(
				$backend->getFileContents( [
					'src' => $backend->getContainerStoragePath( 'createwiki-persistent-model' ) . '/requestmodel.phpml',
				] )
			);
		}
	}

	public function onCreateWikiWritePersistentModel( $pipeline ): bool {
		$backend = MediaWikiServices::getInstance()->getFileBackendGroup()->get( 'wikitide-swift' );
		$backend->prepare( [ 'dir' => $backend->getContainerStoragePath( 'createwiki-persistent-model' ) ] );

		$backend->quickCreate( [
			'dst' => $backend->getContainerStoragePath( 'createwiki-persistent-model' ) . '/requestmodel.phpml',
			'content' => $pipeline,
			'overwrite' => true,
		] );

		return true;
	}

	/**
	 * From WikimediaMessages. Allows us to add new messages,
	 * and override ones.
	 * phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	 *
	 * @param string &$lcKey Key of message to lookup.
	 */
	public function onMessageCache__get( &$lcKey ) {
		// phpcs:enable

		if ( version_compare( MW_VERSION, '1.41', '>=' ) ) {
			return;
		}

		static $keys = [
			'centralauth-groupname',
			'centralauth-login-error-locked',
			'dberr-again',
			'dberr-problems',
			'globalblocking-ipblocked-range',
			'globalblocking-ipblocked-xff',
			'globalblocking-ipblocked',
			'grouppage-autoconfirmed',
			'grouppage-automoderated',
			'grouppage-autoreview',
			'grouppage-blockedfromchat',
			'grouppage-bot',
			'grouppage-bureaucrat',
			'grouppage-chatmod',
			'grouppage-checkuser',
			'grouppage-commentadmin',
			'grouppage-csmoderator',
			'grouppage-editor',
			'grouppage-flow-bot',
			'grouppage-interface-admin',
			'grouppage-moderator',
			'grouppage-no-ipinfo',
			'grouppage-reviewer',
			'grouppage-suppress',
			'grouppage-sysop',
			'grouppage-upwizcampeditors',
			'grouppage-user',
			'importdump-help-reason',
			'importdump-help-target',
			'importdump-help-upload-file',
			'importtext',
			'newsignuppage-loginform-tos',
			'newsignuppage-must-accept-tos',
			'oathauth-step1',
			'prefs-help-realname',
			'privacypage',
			'restriction-delete',
			'restriction-protect',
			'skinname-snapwikiskin',
			'snapwikiskin',
			'webauthn-module-description',
			'wikibase-sitelinks-wikitide',
		];

		if ( in_array( $lcKey, $keys, true ) ) {
			$prefixedKey = "wikitide-$lcKey";
			// MessageCache uses ucfirst if ord( key ) is < 128, which is true of all
			// of the above.  Revisit if non-ASCII keys are used.
			$ucKey = ucfirst( $lcKey );

			$cache = MediaWikiServices::getInstance()->getMessageCache();

			if (
			// Override order:
			// 1. If the MediaWiki:$ucKey page exists, use the key unprefixed
			// (in all languages) with normal fallback order.  Specific
			// language pages (MediaWiki:$ucKey/xy) are not checked when
			// deciding which key to use, but are still used if applicable
			// after the key is decided.
			//
			// 2. Otherwise, use the prefixed key with normal fallback order
			// (including MediaWiki pages if they exist).
			$cache->getMsgFromNamespace( $ucKey, $this->options->get( 'LanguageCode' ) ) === false
			) {
				$lcKey = $prefixedKey;
			}
		}
	}

	/**
	 * From WikimediaMessages
	 * When core requests certain messages, change the key to a WikiTide version.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/MessageCacheFetchOverrides
	 * @param string[] &$keys
	 */
	public function onMessageCacheFetchOverrides( array &$keys ): void {
		static $keysToOverride = [
			'centralauth-groupname',
			'centralauth-login-error-locked',
			'dberr-again',
			'dberr-problems',
			'globalblocking-ipblocked-range',
			'globalblocking-ipblocked-xff',
			'globalblocking-ipblocked',
			'grouppage-autoconfirmed',
			'grouppage-automoderated',
			'grouppage-autoreview',
			'grouppage-blockedfromchat',
			'grouppage-bot',
			'grouppage-bureaucrat',
			'grouppage-chatmod',
			'grouppage-checkuser',
			'grouppage-commentadmin',
			'grouppage-csmoderator',
			'grouppage-editor',
			'grouppage-flow-bot',
			'grouppage-interface-admin',
			'grouppage-moderator',
			'grouppage-no-ipinfo',
			'grouppage-reviewer',
			'grouppage-suppress',
			'grouppage-sysop',
			'grouppage-upwizcampeditors',
			'grouppage-user',
			'importdump-help-reason',
			'importdump-help-target',
			'importdump-help-upload-file',
			'importtext',
			'newsignuppage-loginform-tos',
			'newsignuppage-must-accept-tos',
			'oathauth-step1',
			'prefs-help-realname',
			'privacypage',
			'restriction-delete',
			'restriction-protect',
			'skinname-snapwikiskin',
			'snapwikiskin',
			'webauthn-module-description',
			'wikibase-sitelinks-wikitide',
		];

		$languageCode = $this->options->get( 'LanguageCode' );

		$transformationCallback = static function ( string $key, MessageCache $cache ) use ( $languageCode ): string {
			$transformedKey = "wikitide-$key";

			// MessageCache uses ucfirst if ord( key ) is < 128, which is true of all
			// of the above.  Revisit if non-ASCII keys are used.
			$ucKey = ucfirst( $key );

			if (
				/*
				 * Override order:
				 * 1. If the MediaWiki:$ucKey page exists, use the key unprefixed
				 * (in all languages) with normal fallback order.  Specific
				 * language pages (MediaWiki:$ucKey/xy) are not checked when
				 * deciding which key to use, but are still used if applicable
				 * after the key is decided.
				 *
				 * 2. Otherwise, use the prefixed key with normal fallback order
				 * (including MediaWiki pages if they exist).
				 */
				$cache->getMsgFromNamespace( $ucKey, $languageCode ) === false
			) {
				return $transformedKey;
			}

			return $key;
		};

		foreach ( $keysToOverride as $key ) {
			$keys[$key] = $transformationCallback;
		}
	}

	public function onTitleReadWhitelist( $title, $user, &$whitelisted ) {
		if ( $title->equals( Title::newMainPage() ) ) {
			$whitelisted = true;
			return;
		}

		$specialsArray = [
			'CentralAutoLogin',
			'CentralLogin',
			'ConfirmEmail',
			'CreateAccount',
			'Notifications',
			'OAuth',
			'ResetPassword'
		];

		if ( $user->isAllowed( 'interwiki' ) ) {
			$specialsArray[] = 'Interwiki';
		}

		if ( $title->isSpecialPage() ) {
			$rootName = strtok( $title->getText(), '/' );
			$rootTitle = Title::makeTitle( $title->getNamespace(), $rootName );

			foreach ( $specialsArray as $page ) {
				if ( $rootTitle->equals( SpecialPage::getTitleFor( $page ) ) ) {
					$whitelisted = true;
					return;
				}
			}
		}
	}

	public function onGlobalUserPageWikis( &$list ) {
		$cwCacheDir = $this->options->get( 'CreateWikiCacheDirectory' );
		if ( file_exists( "{$cwCacheDir}/databases-wikitide.json" ) ) {
			$databasesArray = json_decode( file_get_contents( "{$cwCacheDir}/databases-wikitide.json" ), true );
			$list = array_keys( $databasesArray['combi'] );
			return false;
		}

		return true;
	}

	public function onSkinAddFooterLinks( Skin $skin, string $key, array &$footerItems ) {
		if ( $key === 'places' ) {
			$footerItems['termsofservice'] = $this->addFooterLink( $skin, 'termsofservice', 'termsofservicepage' );
			$footerItems['donate'] = $this->addFooterLink( $skin, 'wikitide-donate', 'wikitide-donatepage' );
		}
	}

	public function onSiteNoticeAfter( &$siteNotice, $skin ) {
		$cwConfig = new GlobalVarConfig( 'cw' );

		if ( $cwConfig->get( 'Closed' ) ) {
			if ( $cwConfig->get( 'Private' ) ) {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.wikitide.org/metawiki/0/02/Wiki_lock.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'wikitide-sitenotice-closed-private' )->parse() . '</span></div>';
			} else {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.wikitide.org/metawiki/0/02/Wiki_lock.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'wikitide-sitenotice-closed' )->parse() . '</span></div>';
			}
		} elseif ( $cwConfig->get( 'Inactive' ) && $cwConfig->get( 'Inactive' ) !== 'exempt' ) {
			if ( $cwConfig->get( 'Private' ) ) {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.wikitide.org/metawiki/5/5f/Out_of_date_clock_icon.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'wikitide-sitenotice-inactive-private' )->parse() . '</span></div>';
			} else {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.wikitide.org/metawiki/5/5f/Out_of_date_clock_icon.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'wikitide-sitenotice-inactive' )->parse() . '</span></div>';
			}
		} elseif ( $cwConfig->get( 'Closed' ) && $cwConfig->get( 'Locked' ) ) {
			$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.wikitide.org/metawiki/5/5f/Out_of_date_clock_icon.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'wikitide-sitenotice-closed-locked' )->parse() . '</span></div>';
		}
	}

	public function onGetPreferences( $user, &$preferences ) {
		$preferences['forcesafemode'] = [
			'type' => 'toggle',
			'label-message' => 'prefs-forcesafemode-label',
			'section' => 'rendering',
		];
	}

	public function onBeforeInitialize( $title, $unused, $output, $user, $request, $mediaWiki ) {
		if ( $this->userOptionsManager->getBoolOption( $user, 'forcesafemode' ) ) {
			$request->setVal( 'safemode', '1' );
		}
	}

	public function onContributionsToolLinks( $id, Title $title, array &$tools, SpecialPage $specialPage ) {
		$username = $title->getText();
		$globalUserGroups = CentralAuthUser::getInstanceByName( $username )->getGlobalGroups();

		if (
			!in_array( 'steward', $globalUserGroups ) &&
			!in_array( 'global-sysop', $globalUserGroups ) &&
			!$specialPage->getUser()->isAllowed( 'centralauth-lock' )
		) {
			return;
		}

		if ( !IPUtils::isIPAddress( $username ) ) {
			$tools['centralauth'] = Linker::makeExternalLink(
				'https://meta.wikitide.org/wiki/Special:CentralAuth/' . $username,
				strtolower( $specialPage->msg( 'centralauth' )->text() )
			);
		}
	}

	/**
	 * phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	 *
	 * @param Title $title
	 * @param string &$url
	 * @param string $query
	 */
	public function onGetLocalURL__Internal( $title, &$url, $query ) {
		// phpcs:enable

		if ( defined( 'MW_PHPUNIT_TEST' ) ) {
			return;
		}

		// If the URL contains wgScript, rewrite it to use wgArticlePath
		if ( str_contains( $url, $this->options->get( 'Script' ) ) ) {
			$dbkey = wfUrlencode( $title->getPrefixedDBkey() );
			$url = str_replace( '$1', $dbkey, $this->options->get( 'ArticlePath' ) );
			if ( $query !== '' ) {
				$url = wfAppendQuery( $url, $query );
			}
		}
	}

	private function addFooterLink( $skin, $desc, $page ) {
		if ( $skin->msg( $desc )->inContentLanguage()->isDisabled() ) {
			$title = null;
		} else {
			$title = Title::newFromText( $skin->msg( $page )->inContentLanguage()->text() );
		}

		if ( !$title ) {
			return '';
		}

		return Html::element( 'a',
			[ 'href' => $title->fixSpecialName()->getLinkURL() ],
			$skin->msg( $desc )->text()
		);
	}

	/** Removes redis keys for jobrunner */
	private function removeRedisKey( string $key ) {
		$jobTypeConf = $this->options->get( 'JobTypeConf' );
		if ( !isset( $jobTypeConf['default']['redisServer'] ) || !$jobTypeConf['default']['redisServer'] ) {
			return;
		}

		$hostAndPort = IPUtils::splitHostAndPort( $jobTypeConf['default']['redisServer'] );

		if ( $hostAndPort ) {
			try {
				$redis = new Redis();
				$redis->connect( $hostAndPort[0], $hostAndPort[1] );
				$redis->auth( $jobTypeConf['default']['redisConfig']['password'] );
				$redis->del( $redis->keys( $key ) );
			} catch ( Throwable $ex ) {
				// empty
			}
		}
	}

	/** Remove memcached keys */
	private function removeMemcachedKey( string $key ) {
		$memcacheServer = explode( ':', $this->options->get( 'WikiTideMagicMemcachedServer' ) );

		try {
			$memcached = new Memcached();
			$memcached->addServer( $memcacheServer[0], $memcacheServer[1] );

			// Fetch all keys
			$keys = $memcached->getAllKeys();
			if ( !is_array( $keys ) ) {
				return;
			}

			$memcached->getDelayed( $keys );

			$store = $memcached->fetchAll();

			$keys = $memcached->getAllKeys();
			foreach ( $keys as $item ) {
				// Decide which keys to delete
				if ( preg_match( "/{$key}/", $item ) ) {
					$memcached->delete( $item );
				} else {
					continue;
				}
			}
		} catch ( Throwable $ex ) {
			// empty
		}
	}
}
