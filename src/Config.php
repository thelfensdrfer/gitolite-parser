<?php

namespace VisualAppeal\Gitolite;

use VisualAppeal\Gitolite\Group;
use VisualAppeal\Gitolite\Repository;
use VisualAppeal\Gitolite\User;

class Config
{
	/**
	 * Contents of config file.
	 *
	 * @var string
	 */
	protected $config;

	/**
	 * Path to config.
	 *
	 * @var string
	 */
	private $_path;

	/**
	 * List of all repostitories.
	 *
	 * @var array
	 */
	private $_repostitories = [];

	/**
	 * List of all groups.
	 *
	 * @var array
	 */
	private $_groups = [];

	/**
	 * List of all users without a group.
	 *
	 * @var array
	 */
	private $_users = [];

	/**
	 * Check if the parser is currently parsing a repository.
	 *
	 * @var array
	 */
	private $_parsingRepositories = [];

	/**
	 * Create new parser instance.
	 *
	 * @param string $path Path to config
	 */
	public function __construct($path)
	{
		if (!file_exists($path))
			throw new \Exception(sprintf('Gitolite config file %s does not exist!', $path));

		if (!is_readable($path))
			throw new \Exception(sprintf('Gitolite config file %s is not readable!', $path));

		$this->_path = $path;
		$this->config = file_get_contents($path);

		$i = 1;
		foreach (preg_split("/((\r?\n)|(\r\n?))/", $this->config) as $line) {
			$this->parseLine($line, $i);
			$i++;
		}
	}

	/**
	 * Create group if it does not exist.
	 *
	 * @param string $name
	 * @return Group
	 */
	protected function createOrFindGroup($name)
	{
		if (isset($this->_groups[$name]))
			return $this->_groups[$name];

		$this->_groups[$name] = new Group($name);
		return $this->_groups[$name];
	}

	/**
	 * Create user if it does not exist.
	 *
	 * @param string $name
	 * @return User
	 */
	protected function createOrFindUser($name)
	{
		if (isset($this->_users[$name]))
			return $this->_users[$name];

		$this->_users[$name] = new User($name);
		return $this->_users[$name];
	}

	/**
	 * Create repository if it does not exist.
	 *
	 * @param string $name
	 * @return Repository
	 */
	protected function createOrFindRepository($name)
	{
		if (isset($this->_repostitories[$name]))
			return $this->_repostitories[$name];

		$this->_repostitories[$name] = new Repository($name);
		return $this->_repostitories[$name];
	}

	/**
	 * Parse user and add it to a group
	 *
	 * @param string $user
	 * @return void
	 */
	protected function parseUserlist($user)
	{
		$users = [];

		if (substr($user, 0, 1) === '@') {
			$groupExpanded = $this->createOrFindGroup(trim(substr($user, 1)));
			$usersExpanded = $groupExpanded->getUsers();
			foreach ($usersExpanded as $user) {
				$users[] = $user->getName();
			}
		} else {
			$users[] = trim($user);
		}

		return $users;
	}

	/**
	 * Parse all groups or users and assign them to a group.
	 *
	 * @param string $line
	 * @param Group $group
	 * @return void
	 */
	protected function parseUsers($line, Group $group, $i)
	{
		$line = trim($line);

		$users = preg_split('/\s+/', $line);

		if (count($users) > 0) {
			foreach ($users as $user) {
				$userNames = $this->parseUserlist($user);

				foreach ($userNames as $user) {
					$group->createOrFindUser($user);
				}
			}
		} else {
			$userNames = $this->parseUserlist($line);

			foreach ($userNames as $user) {
				$group->createOrFindUser($user);
			}
		}
	}

	/**
	 * Parse a group from config.
	 *
	 * @param string $line
	 * @param int $i Line number
	 * @return void
	 */
	protected function parseGroup($line, $i)
	{
		if (preg_match('/@([a-zA-z\-0-9]+)\s*?=\s*?(.*)/', $line, $matches) !== 1) {
			throw new \Exception(sprintf('Could not parse group in line #%d: %s', $i, $line));
		}

		$group = $this->createOrFindGroup(trim($matches[1]));
		$this->parseUsers($matches[2], $group, $i);
	}

	/**
	 * Parse permission of repository
	 *
	 * @param string $line
	 * @param int $i Line number
	 * @return Permission
	 */
	protected function parsePermission($line, $i)
	{
		$permission = new Permission;

		if (preg_match('/\s*?(.*)\s*?=\s*?(.*)/', $line, $matches) !== 1) {
			throw new \Exception(sprintf('Could not parse permission in line #%d: %s', $i, $line));
		}

		$left = trim($matches[1]);
		$userList = trim($matches[2]);

		if (preg_match('/([\-\+RW]+)\s*?(.*?)/', $left, $matches) !== 1) {
			throw new \Exception(sprintf('Could not parse permission type in line #%d: %s', $i, $line));
		}

		$permission->setRef(trim($matches[2]));

		$permissionRaw = strtoupper($matches[1]);
		switch ($permissionRaw) {
			case '-':
				$permission->setPermission(Permission::PERMISSION_DENY);
				break;
			case 'R':
				$permission->setPermission(Permission::PERMISSION_READ);
				break;
			case 'RW':
				$permission->setPermission(Permission::PERMISSION_READ_WRITE);
				break;
			case 'RW+':
				$permission->setPermission(Permission::PERMISSION_READ_WRITE_PLUS);
				break;
			default:
				throw new \Exception(sprintf('Unknown permission type in line #%d: %s', $i, $permissionRaw));
				break;
		}

		$users = $this->parseUserlist($userList);
		foreach ($users as $user) {
			$permission->addUser($this->createOrFindUser($user));
		}

		return $permission;
	}

	/**
	 * Parse a group from config.
	 *
	 * @param string $line
	 * @param int $i Line number
	 * @return array
	 */
	protected function parseRepositories($line, $i)
	{
		$repos = [];

		if (substr($line, 0, 4) === 'repo') {
			$repositoryList = trim(substr($line, 4));

			$repositories = preg_split('/\s+/', $repositoryList);
			if (count($repositories) > 0) {
				foreach ($repositories as $repository) {
					$repos[] = $this->createOrFindRepository($repository);
				}
			} else {
				$repos[] = $this->createOrFindRepository($repositoryList);
			}
		} else {
			$repos = $this->_parsingRepositories;

			foreach ($repos as &$repository) {
				$permission = $this->parsePermission($line, $i);
				$repository->addPermission($permission);
			}
		}

		return $repos;
	}

	/**
	 * Parse a config line.
	 *
	 * @param string $line
	 * @return void
	 */
	protected function parseLine($line, $i)
	{
		// Skipt empty lines and comments
		if (strlen($line) === 0 || substr($line, 0, 1) === '#')
			return;

		// Only parse line until comment
		if (strpos($line, '#') !== false)
			$line = substr($line, 0, strpos($line, '#'));

		// Lines starting with @ are groups
		if (substr($line, 0, 1) === '@') {
			$this->_parsingRepo = [];
			return $this->parseGroup($line, $i);
		}

		// Lines starting with "repo" are repostitories
		if (substr($line, 0, 4) === 'repo' || count($this->_parsingRepositories) > 0) {
			$this->_parsingRepositories = $this->parseRepositories($line, $i);
		}
	}

	/**
	 * Return groups.
	 *
	 * @return array
	 */
	public function getGroups()
	{
		return $this->_groups;
	}

	/**
	 * Return repositories.
	 *
	 * @return array
	 */
	public function getRepositories()
	{
		return $this->_repostitories;
	}

	/**
	 * Returns class as string.
	 *
	 * @return string
	 */
	public function __toString()
	{
		$out = $this->_path . ':' . PHP_EOL . PHP_EOL;

		$out .= '## GROUPS' . PHP_EOL . PHP_EOL;
		foreach ($this->_groups as $group) {
			$out .= (string) $group . PHP_EOL;
		}

		$out .= PHP_EOL . '## USERS' . PHP_EOL . PHP_EOL;
		foreach ($this->_users as $user) {
			$out .= (string) $user . PHP_EOL;
		}

		$out .= PHP_EOL . '## REPOSITORIES' . PHP_EOL . PHP_EOL;
		foreach ($this->_repostitories as $repository) {
			$out .= (string) $repository . PHP_EOL;
		}

		return $out;
	}
}
