import 'reflect-metadata';
import { NestFactory } from '@nestjs/core';
import { Module, Injectable, Logger } from '@nestjs/common';
import { SQSEvent, SQSHandler } from 'aws-lambda';

// 1. Settlement Event Interface (matches PHP / Go payload)
interface SettlementEvent {
  transactionReference: string;
  sourceAccountId: string;
  destinationAccountId: string;
  amount: number;
  currency: string;
  timestamp: string;
}

// 2. Settlement Service containing business/processing logic
@Injectable()
class SettlementService {
  private readonly logger = new Logger(SettlementService.name);

  async processRecord(body: string): Promise<void> {
    const event: SettlementEvent = JSON.parse(body);

    this.logger.log(
      `Successfully parsed transaction: ${event.transactionReference} | Amount: ${event.amount} ${event.currency} | From: ${event.sourceAccountId} -> To: ${event.destinationAccountId}`
    );

    // Downstream settlement operations / database audit logging
    this.logger.log('[DUMMY] Executing downstream settlement operations: calling payment provider and updating audit table...');

    // Reconciliation invariant checks (sum debits == sum credits)
    this.logger.log('[DUMMY] Running reconciliation invariant check: validating sum(debits) == sum(credits)...');
  }
}

// 3. Root Application Module
@Module({
  providers: [SettlementService],
})
class AppModule {}

// Cached application context instance across warm Lambda invocations
let cachedAppContext: any = async () => {
  const appContext = await NestFactory.createApplicationContext(AppModule, {
    logger: ['error', 'warn', 'log'],
  });
  return appContext;
};

// 4. AWS Lambda SQS Entrypoint Handler
export const handler: SQSHandler = async (event: SQSEvent): Promise<void> => {
  const appContext = await cachedAppContext();
  const settlementService = appContext.get(SettlementService);
  const logger = new Logger('SQSHandler');

  for (const record of event.Records) {
    logger.log(`Processing SQS Message ID: ${record.messageId}`);
    try {
      await settlementService.processRecord(record.body);
    } catch (error: unknown) {
      const errorMessage = error instanceof Error ? error.message : String(error);
      logger.error(`ERROR: Failed to process SQS message body: ${errorMessage}. Body: ${record.body}`);
      // Throwing the error ensures SQS retries the message or routes it to a DLQ if max retries are hit
      throw error;
    }
  }
};
