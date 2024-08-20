<?php

namespace CommonGateway\ZgwVrijBRPRequestBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use CommonGateway\ZgwVrijBRPRequestBundle\Service\ZaakService;

/**
 * A handler for mapping requests to ZGW cases.
 *
 * @author Wilco Louwerse <wilco@conduction.nl>
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class ZaakHandler implements ActionHandlerInterface
{


    /**
     * The constructor
     *
     * @param ZaakService $zaakService The pet store service
     */
    public function __construct(
        private readonly ZaakService $zaakService
    ) {

    }//end __construct()


    /**
     * Returns the required configuration as a https://json-schema.org array.
     *
     * @return array The configuration that this  action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'         => 'https://example.com/ActionHandler/PetStoreHandler.ActionHandler.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'PetStore ActionHandler',
            'description' => 'This handler returns a welcoming string',
            'required'    => [],
            'properties'  => [
                'source'  => [
                    'type'        => 'string',
                    'description' => 'The source where the requests should be found.',
                    'example'     => 'https://vrijbrp.nl/sources/vrijbrp.requestInbox.source.json',
                    'required'    => true,
                ],
                'mapping' => [
                    'type'        => 'string',
                    'description' => 'The mapping to translate requests to cases',
                    'example'     => 'https://commongateway.nl/mapping/RequestToZaak.mapping.json',
                    'required'    => true,
                ],
            ],
        ];

    }//end getConfiguration()


    /**
     * This function runs the service.
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration of the action
     *
     * @return array
     *
     * @SuppressWarnings("unused") Handlers are strict implementations
     */
    public function run(array $data, array $configuration): array
    {
        return $this->zaakService->syncCaseHandler($data, $configuration);

    }//end run()


}//end class
