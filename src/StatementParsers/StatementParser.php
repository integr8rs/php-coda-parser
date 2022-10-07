<?php

namespace Codelicious\Coda\StatementParsers;

use function Codelicious\Coda\Helpers\filterLinesOfTypes;
use function Codelicious\Coda\Helpers\getCountLinesOfType;
use function Codelicious\Coda\Helpers\getFirstLineOfType;
use Codelicious\Coda\Lines\IdentificationLine;
use Codelicious\Coda\Lines\InformationPart1Line;
use Codelicious\Coda\Lines\InformationPart2Line;
use Codelicious\Coda\Lines\InformationPart3Line;
use Codelicious\Coda\Lines\InitialStateLine;
use Codelicious\Coda\Lines\LineInterface;
use Codelicious\Coda\Lines\LineType;
use Codelicious\Coda\Lines\NewStateLine;
use Codelicious\Coda\Lines\TransactionPart1Line;
use Codelicious\Coda\Lines\TransactionPart2Line;
use Codelicious\Coda\Lines\TransactionPart3Line;
use Codelicious\Coda\Statements\Statement;
use DateTime;

/**
 * @package Codelicious\Coda
 * @author Wim Verstuyf (wim.verstuyf@codelicious.be)
 * @license http://opensource.org/licenses/GPL-2.0 GPL-2.0
 */
class StatementParser
{
	/**
	 * @param LineInterface[] $lines
	 * @return Statement
	 */
	public function parse(array $lines): Statement
	{
		$date = new DateTime("0001-01-01");
		/** @var IdentificationLine $identificationLine */
		$identificationLine = getFirstLineOfType($lines, new LineType(LineType::Identification));
		if ($identificationLine) {
			$date = $identificationLine->getCreationDate()->getValue();
		}

		$initialBalance = 0.0;
		$sequenceNumber = 0;
		/** @var InitialStateLine $initialStateLine */
		$initialStateLine = getFirstLineOfType($lines, new LineType(LineType::InitialState));

		if ($initialStateLine) {
			$initialBalance = $initialStateLine->getBalance()->getValue();
			$sequenceNumber = $initialStateLine->getStatementSequenceNumber()->getValue();
		}


		$newBalance = 0.0;
		$newDate = new DateTime("0001-01-01");
		/** @var NewStateLine $newStateLine */
		$newStateLine = getFirstLineOfType($lines, new LineType(LineType::NewState));
		if ($newStateLine) {
			$newBalance = $newStateLine->getBalance()->getValue();
			$newDate = $newStateLine->getDate()->getValue();
		}

		$messageParser = new MessageParser();
		$informationalMessage = $messageParser->parse(
			filterLinesOfTypes(
				$lines,
				[
					new LineType(LineType::Message)
				]
			)
		);

		$accountParser = new AccountParser();
		$account = $accountParser->parse(
			filterLinesOfTypes(
				$lines,
				[
					new LineType(LineType::Identification),
					new LineType(LineType::InitialState)
				]
			)
		);

		$transactionLineGroups = $this->groupTransactions(
			filterLinesOfTypes(
				$lines,
				[
					new LineType(LineType::TransactionPart1),
					new LineType(LineType::TransactionPart2),
					new LineType(LineType::TransactionPart3),
					new LineType(LineType::InformationPart1),
					new LineType(LineType::InformationPart2),
					new LineType(LineType::InformationPart3)
				]
			)
		);

        $transactionParser = new TransactionParser();
        $filteredTransactionGroups = $transactionParser->filter($transactionLineGroups);

        $transactions = array_map(
			function(array $lines) use ($transactionParser) {
				return $transactionParser->parse($lines);
			}, $filteredTransactionGroups);

		return new Statement(
			$date,
			$account,
			$sequenceNumber,
			$initialBalance,
			$newBalance,
			$newDate,
			$informationalMessage,
			$transactions
		);
	}

	/**
	 * @param LineInterface[] $lines
	 * @return LineInterface[][]
	 */
	private function groupTransactions(array $lines): array
	{
		$transactions = [];
		$idx = -1;
		$sequenceNumber = -1;

		foreach ($lines as $i => $line) {
			/** @var TransactionPart1Line|TransactionPart2Line|TransactionPart3Line|InformationPart1Line|InformationPart2Line|InformationPart3Line $transactionOrInformationLine */
			$transactionOrInformationLine = $line;

			if (
				!$transactions
				|| $sequenceNumber != $transactionOrInformationLine->getSequenceNumber()->getValue()
			) {
				$sequenceNumber = $transactionOrInformationLine->getSequenceNumber()->getValue();
				$idx += 1;

				$transactions[$idx] = [];
			}

			$transactions[$idx][] = $transactionOrInformationLine;
		}

        $transactions = $this->splitCollectiveTransactions($transactions);

		return $transactions;
	}

    private function groupSubTransactions(array $lines): array
    {
        $transactions = [];
        $idx = -1;
        $sequenceNumber = -1;
        $sequenceNumberDetail = -1;
        $transactionPart1LineCount = 0;

        foreach ($lines as $i => $line) {
            /** @var TransactionPart1Line|TransactionPart2Line|TransactionPart3Line|InformationPart1Line|InformationPart2Line|InformationPart3Line $transactionOrInformationLine */
            $transactionOrInformationLine = $line;

            // Skip all lines until the second TransactionPart1Line
            if ($transactionOrInformationLine instanceof TransactionPart1Line) {
                $transactionPart1LineCount++;
            }
            if ($transactionPart1LineCount < 2) {
                continue;
            }

            if (
                !$transactions
                || ($sequenceNumberDetail != $transactionOrInformationLine->getSequenceNumberDetail()->getValue())
            ) {
                $sequenceNumberDetail = $transactionOrInformationLine->getSequenceNumberDetail()->getValue();
                $idx += 1;

                $transactions[$idx] = [];
            }

            $transactions[$idx][] = $transactionOrInformationLine;
        }

        return $transactions;
    }

    /**
     * @param LineInterface[][] $transactions
     *
     * @return LineInterface[][]
     */
    private function splitCollectiveTransactions(array $transactions): array
    {
        $transactionPart1LineType = new LineType(LineType::TransactionPart1);

        $returnedTransactions = [];

        foreach ($transactions as $transaction) {
            if (!$this->isCollectiveTransaction($transaction)) {
                $returnedTransactions[] = [$transaction];

                continue;
            }

            // If a collectiveTransaction somehow only holds one transaction
            if (getCountLinesOfType($transaction, $transactionPart1LineType) === 1) {
                $returnedTransactions[] = [$transaction];

                continue;
            }

            $returnedTransactions[] = $this->groupSubTransactions($transaction);
        }

        return array_merge(...$returnedTransactions);
    }

    /**
     * @param LineInterface[] $transaction
     *
     * @return bool
     */
    private function isCollectiveTransaction(array $transaction): bool
    {
        /** @var TransactionPart1Line|null $transactionPart1Line */
        $transactionPart1Line = getFirstLineOfType($transaction, new LineType(LineType::TransactionPart1));
        return $transactionPart1Line && $transactionPart1Line->getTransactionCode()->getOperation()->getValue() === '07';
    }

}
