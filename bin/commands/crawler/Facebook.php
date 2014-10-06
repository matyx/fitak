<?php

namespace Bin\Commands\Crawler;

use Fitak\Crawler\Facebook as FbCrawler;
use Bin\Commands\Command;
use ElasticSearch;
use Facebook\Entities\AccessToken;
use Fitak\DuplicateEntryException;
use Fitak\InvalidAccessTokenException;
use Fitak\Post;
use Fitak\RepositoryContainer;
use KeyValueStorage;


class Facebook extends Command
{

	/**
	 * @var ElasticSearch
	 * @inject
	 */
	public $elastic;

	/**
	 * @var RepositoryContainer
	 * @inject
	 */
	public $orm;

	protected function configure()
	{
		$this->setName('crawler:facebook');
	}

	public function invoke(FbCrawler $fb, KeyValueStorage $kvs)
	{
		$code = $kvs->get($kvs::FACEBOOK_ACCESS_TOKEN);
		$expires = $kvs->get($kvs::FACEBOOK_ACCESS_TOKEN_EXPIRES);
		$accessToken = new AccessToken($code, $expires);

		if (!$code || $accessToken->getExpiresAt()->getTimestamp() <= time())
		{
			throw new InvalidAccessTokenException("Authenticate by invoking 'auth:facebook'");
		}

		if (!$accessToken->isLongLived() || $accessToken->getExpiresAt()->getTimestamp() < strToTime('+1 week'))
		{
			$accessToken = $accessToken->extend();
			$this->out->writeln("<info>Extended access token</info>");
			$kvs->save($kvs::FACEBOOK_ACCESS_TOKEN, (string) $accessToken);
		}

		if ($this->out->isVerbose())
		{
			$expires = $accessToken->getExpiresAt()->format('Y-m-d H:i');
			$this->out->writeln("Token expires at $expires");
		}

		$fb->setSession($accessToken);

		$timestamp = time();
		$since = $kvs->get($kvs::CRAWLER_SINCE);

		$flushCounter = 0;
		foreach ($this->orm->groups->findAll() as $group)
		{
			$this->indexGroup($fb, $group, $since);

			if (++$flushCounter > 50)
			{
				$this->orm->flush();
			}
		}

		// We are saving timestamp from before the crawl so
		// on next iteration we might get some duplicates but
		// we will never lose any posts if crawler is stopped
		$kvs->save($kvs::CRAWLER_SINCE, $timestamp);
	}

	/**
	 * @param FbCrawler $fb
	 * @param \StdClass $group
	 * @param int $since timestamp
	 */
	private function indexGroup(FbCrawler $fb, $group, $since)
	{
		$this->out->writeln("<info>Processing '$group->name'</info>");

		foreach ($fb->getGroupFeedSince($group->id, $since) as $post)
		{
			if ($this->out->isVerbose() && !$this->out->isVeryVerbose())
			{
				$this->out->writeln("    <info>post $post->id ($post->created_time)</info>");
			}
			$this->indexEntry($group, $post, NULL);

			$comments = isset($post->comments) ? $post->comments->data : [];
			foreach ($comments as $comment)
			{
				$this->indexEntry($group, $comment, $post);
			}
		}
	}

	/**
	 * @param \StdClass $group
	 * @param \StdClass $entry
	 * @param NULL|\StdClass $parentTopic
	 */
	protected function indexEntry($group, $entry, $parentTopic = NULL)
	{
		if ($this->out->isVeryVerbose())
		{
			$this->out->write($parentTopic ? 'c' : 'p');
		}

		$post = new Post;

		$post->id = preg_replace('~^\d+_~', '', $entry->id);
		$post->message = $entry->message !== NULL ? $entry->message : ''; // can be NULL if caption only picture post is shared
		$post->createdTime = $entry->created_time;
		$post->updatedTime = $entry->updated_time ?: $entry->created_time;
		//$post->commentsCount; // TODO deprecate
		$post->likesCount = $entry->like_count ?: count($entry->likes);
		$post->fromName = $entry->from->name;
		$post->fromId = $entry->from->id;
		$post->caption = strpos($entry->caption, 'Attachment Unavailable') === FALSE ? $entry->caption : NULL;

		$post->type = $entry->type ?: $post::TYPE_STATUS;
		if ($post->type === $post::TYPE_LINK)
		{
			$post->picture = $entry->picture;
			$post->link = $entry->link;
			$post->description = $entry->description;
		}
		else if ($post->type === $post::TYPE_PHOTO)
		{
			$post->picture = $entry->picture;
			$post->link = $entry->link;
		}
		else if ($post->type === $post::TYPE_VIDEO)
		{
			$post->source = $entry->source;
			$post->description = $entry->description;
		}

		$this->orm->posts->attach($post);
		$post->parent = $parentTopic->id;
		$post->group = $group->id;

		try
		{
			$this->orm->posts->persist($post);
		}
		catch (DuplicateEntryException $e)
		{
			// otherwise ignore, fb returned same post multiple times
		}
	}

}
