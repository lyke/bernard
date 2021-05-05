<?php

namespace Bernard\Normalizer;

interface AggregateNormalizerAware
{
    function setAggregateNormalizer(AggregateNormalizer $aggregate);
}
