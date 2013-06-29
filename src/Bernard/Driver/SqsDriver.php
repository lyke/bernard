<?php

namespace Bernard\Driver;

use Aws\Sqs\SqsClient;
use Aws\Sqs\Enum\QueueAttribute;
use Bernard\Message\Envelope;
use SplQueue;

/**
 * Implements a Driver for use with AWS SQS client API:
 * http://docs.aws.amazon.com/aws-sdk-php-2/latest/class-Aws.Sqs.SqsClient.html
 *
 * @package Bernard
 */
class SqsDriver implements \Bernard\Driver
{
    protected $sqs;
    protected $queueUrls;
    protected $queueAttributes;
    protected $caches = array();

    /**
     * @param SqsClient $client
     * @param array     $queueAttributes
     * @param array     $queueUrls
     */
    public function __construct(SqsClient $sqs, $queueAttributes = array(), array $queueUrls = array())
    {
        $this->sqs             = $sqs;
        $this->queueAttributes = array('Attributes' => $queueAttributes);
        $this->queueUrls       = $queueUrl;
    }

    /**
     * {@inheritDoc}
     */
    public function countMessages($queueName)
    {
        $queueUrl = $this->resolveUrl($queueName);

        $result = $this->sqs->getQueueAttributes(array(
            'QueueUrl'       => $queueUrl,
            'AttributeNames' => array(QueueAttribute::APPROXIMATE_NUMBER_OF_MESSAGES)
        ));

        return $result->get(QueueAttribute::APPROXIMATE_NUMBER_OF_MESSAGES) ?: 0;
    }

    /**
     * {@inheritDoc}
     */
    public function createQueue($queueName)
    {
        $result = $this->sqs->createQueue($this->queueAttributes, array('QueueName' => $queueName));

        $this->queueUrls[$queueName] = $result->get('QueueUrl');
    }

    /**
     * {@inheritDoc}
     */
    public function removeQueue($queueName)
    {
        $queueUrl = $this->resolveUrl($queueName);

        unset($this->queueUrls[$queueName]);

        $this->sqs->deleteQueue(array('QueueUrl' => $queueUrl));
    }

    /**
     * {@inheritDoc}
     */
    public function listQueues()
    {
        $result = $this->sqs->listQueues();

        if (!$queueUrls = $result->get('QueueUrls')) {
            return array();
        }

        foreach ($queueUrls as $queueUrl) {
            if (false !== array_search($queueUrl, $this->queueUrls)) {
                continue;
            }

            $queueName = current(array_reverse(explode('/', $queueUrl)));
            $this->queueUrls[$queueName] = $queueUrl;
        }

        return $this->queueUrls;
    }

    /**
     * {@inheritDoc}
     */
    public function pushMessage($queueName, $message)
    {
        $queueUrl = $this->resolveUrl($queueName);

        $this->sqs->sendMessage(array(
            'QueueUrl'    => $queueUrl,
            'MessageBody' => $message
        ));
    }

    /**
     * As it is costly to make a request for messages from SQS we get a couple instead of a single
     * theese are cached and returned before making a new call.
     *
     * {@inheritDoc}
     */
    public function popMessage($queueName, $interval = 5)
    {
        if (!isset($this->caches[$queueName])) {
            $this->caches[$queueName] = new SplQueue;
        }

        $queueUrl = $this->resolveUrl($queueName);
        $cache = $this->caches[$queueName];

        if ($cache->count()) {
            return $cache->dequeue();
        }

        $result = $this->sqs->receiveMessage(array(
            'QueueUrl'            => $queueUrl,
            'MaxNumberOfMessages' => 4,
            'WaitTimeSeconds'     => $interval
        ));

        if (!$messages = $result->get('Messages')) {
            return array(null, null);
        }

        foreach ($messages as $message) {
            $cache->enqueue(array($message['Body'], $message['ReceiptHandle']));
        }

        return $cache->dequeue();
    }

    /**
     * {@inheritDoc}
     */
    public function acknowledgeMessage($queueName, $receipt)
    {
        $queueUrl = $this->resolveUrl($queueName);

        $this->sqs->deleteMessage(array(
            'QueueUrl'      => $queueUrl,
            'ReceiptHandle' => $receipt,
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function peekQueue($queueName, $index = 0, $limit = 20)
    {
        return array();
    }

    /**
     * {@inheritDoc}
     */
    public function info()
    {
        // info per queue would be possible.. return info about all queues here?
        return null;
    }

    /**
     * AWS works with queue URLs rather than queue names. Returns either queue URL (if queue exists) for given name or null if not.
     *
     * @param  string $queueName
     * @return mixed
     */
    protected function resolveUrl($queueName)
    {
        if (isset($this->queueUrls[$queueName])) {
            return $this->queueUrls[$queueName];
        }

        $result = $this->sqs->getQueueUrl(array(
            'QueueName' => $queueName,
        ));

        if ($queueUrl = $result->get('QueueUrl')) {
            return $this->queueUrls[$queueName] = $queueUrl;
        }

        throw new \InvalidArgumentException('Queue "' . $queueName .'" cannot be resolved to an url.');
    }

}
